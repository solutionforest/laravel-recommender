<?php

namespace SolutionForest\Recommender;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Jobs\BuildFeed;
use SolutionForest\Recommender\Scoring\Blender;
use SolutionForest\Recommender\Scoring\Diversifier;
use SolutionForest\Recommender\Scoring\Explorer;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Cursor;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\FeedPage;
use SolutionForest\Recommender\Support\Ids;
use Throwable;

class Recommender
{
    /** @var array<string, FeedDefinition> */
    private array $definitions = [];

    public function __construct(
        private readonly Container $container,
        private readonly FeedCache $cache,
        private readonly Bindings $bindings,
        private readonly Storage\ScoreStore $scores,
        private readonly Blender $blender = new Blender(),
        private readonly Explorer $explorer = new Explorer(),
        private readonly Diversifier $diversifier = new Diversifier(),
    ) {
    }

    public function feed(string $name): FeedDefinition
    {
        if (isset($this->definitions[$name])) {
            return $this->definitions[$name];
        }

        $feeds = (array) config('recommender.feeds', []);
        if (! isset($feeds[$name])) {
            throw new InvalidArgumentException("Recommender feed [{$name}] is not configured.");
        }

        // Package defaults < app 'defaults' < preset < feed config. Merged here
        // (not only via mergeConfigFrom, which is shallow) so a published config
        // file that lists only a few defaults still gets every key.
        $defaults = array_replace(self::packageDefaults(), (array) config('recommender.defaults', []));
        $feed = (array) $feeds[$name];

        if (isset($feed['preset']) && $feed['preset'] !== '') {
            $presets = $this->presets();
            $preset = is_string($feed['preset']) ? $feed['preset'] : '';
            if (! isset($presets[$preset])) {
                throw new InvalidArgumentException("Recommender feed [{$name}] uses unknown preset [{$preset}]. Available: ".implode(', ', array_keys($presets)).'.');
            }
            $defaults = array_replace($defaults, (array) $presets[$preset]);
        }

        return $this->definitions[$name] = FeedDefinition::make($name, $defaults, $feed);
    }

    /**
     * The one feed whose `model` is $model.
     *
     * @param  class-string|Model  $model
     */
    public function feedFor(string|Model $model): string
    {
        $class = $model instanceof Model ? $model::class : $model;
        $names = [];
        foreach ((array) config('recommender.feeds', []) as $name => $config) {
            if (is_array($config) && ($config['model'] ?? null) === $class) {
                $names[] = (string) $name;
            }
        }

        return match (count($names)) {
            1 => $names[0],
            0 => throw new InvalidArgumentException("No recommender feed has 'model' => {$class}::class. Add one to config/recommender.php or run php artisan recommender:bootstrap."),
            default => throw new InvalidArgumentException("Several recommender feeds use {$class} (".implode(', ', $names)."); pass the feed name, e.g. recommendedFor(\$user, '{$names[0]}')."),
        };
    }

    /**
     * Package presets, overridden / extended by the app's `presets`.
     *
     * @return array<string, mixed>
     */
    public function presets(): array
    {
        static $package = null;
        $package ??= (array) ((require __DIR__.'/../config/recommender.php')['presets'] ?? []);

        return array_replace($package, (array) config('recommender.presets', []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function packageDefaults(): array
    {
        static $defaults = null;

        return $defaults ??= (array) ((require __DIR__.'/../config/recommender.php')['defaults'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function feeds(): array
    {
        return array_keys((array) config('recommender.feeds', []));
    }

    public function enabled(): bool
    {
        return (bool) config('recommender.enabled', true);
    }

    /**
     * Serve one page. This is the hot path: cache reads only, plus one
     * primary-key query for cards that are not cached yet.
     *
     * Page 1 reuses the user's list while it is fresh and still has unseen
     * items; otherwise it rebuilds. Later pages continue from the same list,
     * skipping everything already served (the seen-set), and top up with the
     * trending list when the personal list runs out.
     *
     * Pass the user model (or its id, or null for guests). The page number
     * defaults to the request's `?page=` like Eloquent's paginate().
     *
     * @param  Authenticatable|int|string|null  $user  the viewer; a model is also available to sources as `$context->attribute('user')`
     * @param  array<string, mixed>  $attributes  passed to sources/filters via Context
     */
    public function page(string $feedName, Authenticatable|int|string|null $user = null, ?int $page = null, ?int $perPage = null, array $attributes = []): FeedPage
    {
        [$userId, $attributes] = $this->viewer($user, $attributes);
        $feed = $this->feed($feedName);
        $page = max(1, $page ?? Cursor::currentPage($feedName) ?? Paginator::resolveCurrentPage());
        $perPage = max(1, $perPage ?? $feed->int('page_size', 12));

        if ($userId === null || ! $this->enabled()) {
            return $this->trendingPage($feed, $page, $perPage, $attributes);
        }

        $lv = $this->listVariant($feed, $attributes);
        $sv = $this->seenVariant($feed, $attributes);
        $seen = $this->cache->seen($feed, $userId, $sv);
        $seenSet = array_flip($seen);
        $origin = 'personal';

        $list = $this->cache->list($feed, $userId, $lv);
        $ids = $list['ids'] ?? [];
        $unseen = array_values(array_filter($ids, fn ($id) => ! isset($seenSet[$id])));

        $age = $list === null ? PHP_INT_MAX : time() - $list['built_at'];
        $stale = $list === null
            || $age > $feed->int('feed_ttl', 1800)
            || ($page === 1 && count($unseen) < $perPage && $age > $feed->int('min_rebuild_interval', 60));

        if ($stale) {
            $built = $this->buildWithLock($feed, $userId, $seen, $attributes);
            if ($built !== null) {
                $ids = $built;
                $unseen = array_values(array_filter($built, fn ($id) => ! isset($seenSet[$id])));
            }
        }

        if (count($unseen) < $perPage + 1) {
            // Personal list (nearly) exhausted: continue with trending.
            // Shared trending is built without a userId, so re-apply the
            // feed filter (e.g. hide the viewer's own posts) before merging.
            $unseen = $this->appendAllowed(
                $feed,
                $userId,
                $unseen,
                $this->trending($feedName, $attributes),
                $seenSet,
                $attributes,
            );
            if ($ids === []) {
                $origin = 'trending';
            }
        }

        $tail = [];
        if (count($unseen) < $perPage + 1) {
            // Ranked and trending items used up: continue with the catalog.
            [$unseen, $tail] = $this->appendTail($feed, $userId, $unseen, $seenSet, $attributes, $perPage + 1 - count($unseen));
        }

        if ($page === 1 && count($unseen) < $perPage) {
            // Never show an empty first screen: allow previously seen items.
            $unseen = $this->appendAllowed(
                $feed,
                $userId,
                $unseen,
                array_merge($ids, $this->trending($feedName, $attributes)),
                [],
                $attributes,
                $perPage,
            );
        }

        [$cards, $servedIds, $consumed] = $this->render($feed, $unseen, $perPage, $attributes);
        $added = array_flip(array_map('strval', $this->cache->markSeen($feed, $userId, $consumed, $sv)));
        // Count an impression the first time an item is shown to this user
        // (within the seen window): refreshes, retries and the page-1
        // "allow seen items" fallback do not inflate it.
        $this->recordImpressions($feed, array_values(array_filter($servedIds, fn ($id) => isset($added[(string) $id]))));
        $this->hintIfEmpty($feed, $page, $servedIds);

        $remaining = max(0, count($unseen) - count($consumed));
        if ($servedIds !== [] && $tail !== [] && array_diff(array_map('strval', $servedIds), array_keys($tail)) === []) {
            $origin = 'tail';
        }

        return new FeedPage(
            items: array_values($cards),
            ids: $servedIds,
            page: $page,
            perPage: $perPage,
            hasMore: $remaining > 0,
            remaining: $remaining,
            origin: $origin,
            feed: $feed->name,
            model: is_string($feed->get('model')) ? $feed->get('model') : null,
            sequential: true,
        );
    }

    /**
     * Build and store the ranked list for one user. Safe to call from a job.
     *
     * @param  list<int|string>|null  $seen
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>
     */
    public function build(string $feedName, Authenticatable|int|string $user, ?array $seen = null, array $attributes = []): array
    {
        [$userId, $attributes] = $this->viewer($user, $attributes);
        $userId ??= throw new InvalidArgumentException('Cannot build a feed for a user without an id.');
        $feed = $this->feed($feedName);
        $seen ??= $this->cache->seen($feed, $userId, $this->seenVariant($feed, $attributes));
        $context = new Context($feed, $userId, $seen, $attributes, CarbonImmutable::now());

        $ids = $this->rank($context, $feed->int('feed_size', 200));
        $this->cache->putList($feed, $userId, $ids, $this->listVariant($feed, $attributes));

        return $ids;
    }

    /**
     * Shared list used for guests, the kill switch and as a fallback.
     * Built at most once per trending_ttl for everyone.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>
     */
    public function trending(string $feedName, array $attributes = []): array
    {
        $feed = $this->feed($feedName);

        return $this->cache->trending($feed, function () use ($feed, $attributes) {
            try {
                $context = new Context($feed, null, [], $attributes, CarbonImmutable::now());

                return $this->rank($context, $feed->int('trending_size', 200), trendingOnly: true);
            } catch (Throwable $e) {
                $this->report($feed, 'trending', $e);

                return null; // not cached: retried on the next request
            }
        }, $this->listVariant($feed, $attributes));
    }

    /**
     * Queue a background rebuild (e.g. on login / app open). Pass the
     * attributes listed in `list_vary_by` to warm that variant of the list.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function warm(string $feedName, Authenticatable|int|string $user, array $attributes = []): void
    {
        $this->feed($feedName);
        BuildFeed::dispatch(
            $feedName,
            $this->viewer($user)[0] ?? throw new InvalidArgumentException('Cannot warm a feed for a user without an id.'),
            $attributes,
        );
    }

    /**
     * The cache variant of a list for these attributes (`list_vary_by`), or
     * null when the feed does not vary its lists.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function listVariant(FeedDefinition|string $feed, array $attributes): ?string
    {
        $feed = $feed instanceof FeedDefinition ? $feed : $this->feed($feed);

        return FeedCache::variant($feed->list('list_vary_by'), $attributes);
    }

    /**
     * The seen-set variant. Defaults to `tenant` when it is in `list_vary_by`
     * (tenants have disjoint items); `device` / `locale` stay shared so a user
     * sees no repeats across devices. Override with `seen_vary_by`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function seenVariant(FeedDefinition $feed, array $attributes): ?string
    {
        $names = $feed->get('seen_vary_by') !== null
            ? $feed->list('seen_vary_by')
            : array_values(array_intersect($feed->list('list_vary_by'), ['tenant']));

        return FeedCache::variant($names, $attributes);
    }

    /**
     * Record an interaction in the feed's own table, with its configured
     * columns, e.g. `recordInteraction('posts', $post, $user, 'like', once: true)`.
     * Plain insert; `once: true` makes it idempotent (needs a unique index on
     * user + item) and returns false when it already existed. Unliking is
     * your own delete: scores are decayed counters and do not go down.
     * Fires no events and dispatches no jobs; scores update on the schedule.
     *
     * @param  ?string  $table  only when two tables share the event name
     * @return bool true when a row was added
     */
    public function recordInteraction(string $feedName, Model|int|string $item, Authenticatable|int|string $user, string $event, bool $once = false, ?string $table = null, ?\DateTimeInterface $at = null): bool
    {
        $feed = $this->feed($feedName);
        $source = $this->bindings->interactions($feed);
        if (! $source instanceof Interactions\DatabaseInteractions) {
            throw new \LogicException("Feed [{$feedName}] reads interactions from a class; record them where that class reads them, or switch to a table map ('interactions' => ['table' => 'event']).");
        }

        $userId = $this->viewer($user)[0] ?? throw new InvalidArgumentException('Cannot record an interaction for a user without an id.');
        $itemId = $item instanceof Model ? $item->getKey() : $item;
        if (! is_int($itemId) && ! is_string($itemId)) {
            throw new InvalidArgumentException('Cannot record an interaction for an item without a key.');
        }

        return $source->record($userId, $itemId, $event, $once, $table, $at);
    }

    /** Drop a cached card, e.g. after the item was edited or hidden. */
    public function forgetCard(string $feedName, int|string $id): void
    {
        $this->cache->forgetCard($this->feed($feedName), $id);
    }

    /**
     * How an item is doing, from the scores update-scores keeps:
     *
     *  - positive / exposure: e.g. likes / views (per `positive_events` / `exposure_events`)
     *  - rate: positive ÷ exposure (null without exposures)
     *  - quality: Wilson lower bound of that rate, 0..1 — a confident "how
     *    good", so 1 like out of 1 view does not beat 50 out of 100
     *  - popularity: time-decayed activity relative to the hottest item now, 0..1
     *
     * Items without any scored interaction are returned with zeros. With
     * `impressions` on, rate and quality use positive ÷ impressions (items
     * actually shown). Pass `$days` for the last N days only (today included);
     * popularity and last_event_at stay lifetime values.
     *
     * @param  int|string|Model|list<int|string|Model>  $items
     * @return array<int|string, array{positive: int, exposure: int, impressions: int, rate: ?float, quality: float, popularity: float, last_event_at: ?string}>
     */
    public function stats(string $feedName, int|string|Model|array $items, ?int $days = null): array
    {
        $feed = $this->feed($feedName);
        $ids = array_map(
            fn ($item) => $item instanceof Model ? $item->getKey() : $item,
            is_array($items) ? $items : [$items],
        );
        $ids = Ids::normalizeAll(array_filter($ids, fn ($id) => is_int($id) || is_string($id)));

        $found = $this->scores->rows($feed->name, $ids);
        $window = $days === null ? null : $this->scores->window($feed->name, $ids, $days, CarbonImmutable::now());
        $basis = $feed->get('impressions') ? 'impressions' : 'exposure';
        $out = [];
        foreach ($ids as $id) {
            $row = $found['rows'][$id] ?? ['hot' => null, 'positive' => 0, 'exposure' => 0, 'impressions' => 0, 'last_event_at' => null];
            $counts = $window === null ? $row : ($window[$id] ?? ['positive' => 0, 'exposure' => 0, 'impressions' => 0]);
            $out[$id] = [
                'positive' => $counts['positive'],
                'exposure' => $counts['exposure'],
                'impressions' => $counts['impressions'],
                // Same denominator as the Wilson score: never above 100% even
                // when likes outnumber recorded views.
                'rate' => $counts[$basis] > 0 ? round($counts['positive'] / max($counts[$basis], $counts['positive']), 4) : null,
                'quality' => round(Scoring\Math::wilson($counts['positive'], $counts[$basis]), 4),
                'popularity' => $row['hot'] === null || $found['max_hot'] === null ? 0.0 : round(exp($row['hot'] - $found['max_hot']), 4),
                'last_event_at' => $row['last_event_at'],
            ];
        }

        return $out;
    }

    /**
     * Drop the cached card of $model in every feed whose `model` is its class
     * (used by the Recommendable trait on save / delete).
     */
    public function forgetModel(Model $model): void
    {
        foreach ((array) config('recommender.feeds', []) as $name => $config) {
            if (($config['model'] ?? null) === $model::class && $model->getKey() !== null) {
                $this->forgetCard((string) $name, $model->getKey());
            }
        }
    }

    /**
     * Forget a user's list and seen-set (e.g. "reset my recommendations").
     * With `list_vary_by`, pass the attributes of the variant to reset.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function forgetUser(string $feedName, Authenticatable|int|string $user, array $attributes = []): void
    {
        $userId = $this->viewer($user)[0] ?? throw new InvalidArgumentException('Cannot forget a user without an id.');
        $feed = $this->feed($feedName);
        $this->cache->forgetList($feed, $userId, $this->listVariant($feed, $attributes));
        $this->cache->forgetSeen($feed, $userId, $this->seenVariant($feed, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes  the `list_vary_by` variant to drop
     */
    public function forgetTrending(string $feedName, array $attributes = []): void
    {
        $feed = $this->feed($feedName);
        $this->cache->forgetTrending($feed, $this->listVariant($feed, $attributes));
    }

    /**
     * Explain a user's ranking: per-source contributions of the top items.
     * For debugging / admin only — runs a full build without storing it.
     *
     * Rows are in blended-score order (before exploration and diversity).
     * `sources` are each source's raw score; `contributions` are the parts of
     * `score` (weight × normalised value), so they sum to `score`.
     * `final` is the 1-based position after exploration and diversity in this
     * fresh build (exploration is shuffled, so it can vary between runs);
     * `cached` is the position in the list the user is served right now
     * (null when not in it, or when nothing is cached). Exploration-only
     * items that reach the final top `$limit` are appended with
     * `exploration: true` and a zero score.
     *
     * @param  array<string, mixed>  $attributes  as passed to `page()`, so tenant-aware feeds explain what that tenant sees
     * @return list<array{id: int|string, score: float, sources: array<string, float>, contributions: array<string, float>, final: ?int, cached: ?int, exploration: bool}>
     */
    public function explain(string $feedName, Authenticatable|int|string|null $user, int $limit = 20, array $attributes = []): array
    {
        [$userId, $attributes] = $this->viewer($user, $attributes);
        $feed = $this->feed($feedName);
        $lv = $this->listVariant($feed, $attributes);
        $context = new Context($feed, $userId, [], $attributes, CarbonImmutable::now());
        $parts = $this->collect($context, $feed->int('source_limit', 200), $userId === null);

        $final = array_flip(array_map('strval', $this->order($context, $parts, $feed->int('feed_size', 200), $userId === null)));
        $cachedList = $userId === null
            ? $this->cache->peekTrending($feed, $lv)
            : ($this->cache->list($feed, $userId, $lv)['ids'] ?? null);
        $cached = $cachedList === null ? [] : array_flip(array_map('strval', $cachedList));

        $max = [];
        foreach ($parts['sources'] as $class => $scores) {
            $max[$class] = $scores === [] ? 0.0 : max($scores);
        }

        $out = [];
        foreach (array_slice($parts['blended'], 0, $limit, true) as $id => $score) {
            $sources = $contributions = [];
            foreach ($parts['sources'] as $class => $scores) {
                if (isset($scores[$id])) {
                    $sources[class_basename($class)] = round($scores[$id], 4);
                    $weight = $parts['weights'][$class] ?? 0.0;
                    if ($weight > 0 && $max[$class] > 0) {
                        $contributions[class_basename($class)] = round($weight * max(0.0, $scores[$id]) / $max[$class], 4);
                    }
                }
            }
            foreach ($parts['boosts'] as $class => $values) {
                if (isset($values[$id])) {
                    $sources[class_basename($class)] = round($values[$id], 4);
                    $weight = $parts['boost_weights'][$class] ?? 0.0;
                    if ($weight > 0) {
                        $contributions[class_basename($class)] = round($weight * min(1.0, max(0.0, $values[$id])), 4);
                    }
                }
            }
            $out[] = [
                'id' => $id,
                'score' => round($score, 4),
                'sources' => $sources,
                'contributions' => $contributions,
                'final' => self::position($final, $id),
                'cached' => self::position($cached, $id),
                'exploration' => false,
            ];
        }

        // Exploration-only items that made it into the final top $limit.
        $listed = array_flip(array_map(fn ($row) => (string) $row['id'], $out));
        foreach (array_slice(array_keys($final), 0, $limit) as $id) {
            if (! isset($listed[(string) $id]) && ! isset($parts['blended'][$id])) {
                $out[] = [
                    'id' => Ids::normalize((string) $id),
                    'score' => 0.0,
                    'sources' => [],
                    'contributions' => [],
                    'final' => self::position($final, $id),
                    'cached' => self::position($cached, $id),
                    'exploration' => true,
                ];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------

    /**
     * @param  list<int|string>  $seen
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>|null  null when another request is building and nothing is cached
     */
    private function buildWithLock(FeedDefinition $feed, int|string $userId, array $seen, array $attributes): ?array
    {
        $lock = $this->cache->lock($feed, $userId, $this->listVariant($feed, $attributes));

        try {
            return $lock->block($feed->int('build_lock_wait', 2), function () use ($feed, $userId, $seen, $attributes) {
                return $this->build($feed->name, $userId, $seen, $attributes);
            });
        } catch (LockTimeoutException) {
            // Someone else is building: use whatever is there now.
            return $this->cache->list($feed, $userId, $this->listVariant($feed, $attributes))['ids'] ?? null;
        } catch (Throwable $e) {
            $this->report($feed, 'build', $e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function trendingPage(FeedDefinition $feed, int $page, int $perPage, array $attributes): FeedPage
    {
        $ids = $this->trending($feed->name, $attributes);
        $offset = ($page - 1) * $perPage;
        $window = array_slice($ids, $offset);

        $origin = 'trending';
        if (count($window) < $perPage + 1 && ($tail = $this->tail($feed)) !== null) {
            // Guests page by offset: continue into the catalog after trending.
            $context = new Context($feed, null, [], $attributes, CarbonImmutable::now());
            $more = $tail->ids($context, $ids, $perPage + 1 - count($window), max(0, $offset - count($ids)));
            if ($window === [] && $more !== []) {
                $origin = 'tail';
            }
            $window = array_merge($window, $more);
        }

        [$cards, $servedIds, $consumed] = $this->render($feed, $window, $perPage, $attributes);
        $this->recordImpressions($feed, $servedIds);
        $this->hintIfEmpty($feed, $page, $servedIds);
        $remaining = max(0, count($window) - count($consumed));

        return new FeedPage(
            items: array_values($cards),
            ids: $servedIds,
            page: $page,
            perPage: $perPage,
            hasMore: $remaining > 0,
            remaining: $remaining,
            origin: $origin,
            feed: $feed->name,
            model: is_string($feed->get('model')) ? $feed->get('model') : null,
        );
    }

    /**
     * Take up to $perPage renderable cards from the front of $ids.
     *
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $attributes
     * @return array{0: array<int|string, array<string, mixed>>, 1: list<int|string>, 2: list<int|string>}
     *         cards, ids of the cards, ids consumed from the list (incl. ones that failed to render)
     */
    private function render(FeedDefinition $feed, array $ids, int $perPage, array $attributes = []): array
    {
        $transformer = $this->bindings->cards($feed);
        [$variant, $attributes] = $this->cardVariant($feed, $attributes);
        if ($transformer instanceof Eloquent\ModelCards) {
            $transformer = $transformer->withAttributes($attributes);
        }

        $cards = [];
        $consumed = [];
        $offset = 0;
        // Items can disappear between build and render (hidden, deleted);
        // fetch a little more until the page is full. Bounded to 3 rounds.
        for ($round = 0; $round < 3 && count($cards) < $perPage && $offset < count($ids); $round++) {
            $need = $perPage - count($cards);
            $batch = array_slice($ids, $offset, $need + ($round === 0 ? 0 : $need));
            $offset += count($batch);
            $rendered = $this->cache->cards($feed, $batch, fn (array $missing) => $transformer->cards($missing), $variant);
            foreach ($batch as $id) {
                $consumed[] = $id;
                if (isset($rendered[$id]) && count($cards) < $perPage) {
                    $cards[$id] = $rendered[$id];
                }
            }
        }

        return [$cards, array_map(fn ($id) => Ids::normalize($id), array_keys($cards)), $consumed];
    }

    /**
     * Cache variant for cards that differ per attribute (`cards_vary_by`,
     * e.g. ['locale']). `locale` falls back to the app locale.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: ?string, 1: array<string, mixed>}  variant (null = shared cards), attributes
     */
    private function cardVariant(FeedDefinition $feed, array $attributes): array
    {
        $keys = $feed->list('cards_vary_by');
        if ($keys === []) {
            return [null, $attributes];
        }
        if (in_array('locale', $keys, true) && ! isset($attributes['locale'])) {
            $attributes['locale'] = app()->getLocale();
        }
        $parts = [];
        foreach ($keys as $key) {
            $value = $attributes[$key] ?? '';
            $parts[] = $key.'='.(is_scalar($value) ? (string) $value : md5((string) json_encode($value)));
        }

        return [implode('|', $parts), $attributes];
    }

    /**
     * @return list<int|string>
     */
    private function rank(Context $context, int $size, bool $trendingOnly = false): array
    {
        $parts = $this->collect($context, $context->feed->int('source_limit', 200), $trendingOnly);

        return $this->order($context, $parts, $size, $trendingOnly);
    }

    /**
     * Blended order → exploration interleave → diversity.
     *
     * @param  array{blended: array<int|string, float>, exploration: list<int|string>}  $parts
     * @return list<int|string>
     */
    private function order(Context $context, array $parts, int $size, bool $trendingOnly): array
    {
        $feed = $context->feed;
        $ranked = array_keys($parts['blended']);

        $explore = [];
        if (! $trendingOnly) {
            foreach ($parts['exploration'] as $id) {
                $explore[] = $id;
            }
            shuffle($explore);
        }

        $ids = $this->explorer->interleave(array_slice($ranked, 0, $size), $explore, $feed->float('exploration_ratio', 0.15));
        $ids = array_slice($ids, 0, $size);

        $diversity = (array) $feed->get('diversity', []);
        $groups = $diversity !== [] && $ids !== [] ? $this->bindings->groups($feed) : null;
        if ($groups !== null) {
            $ids = $this->diversifier->apply($ids, $groups->groups($ids), $diversity, $feed->int('page_size', 12));
        }

        return Ids::normalizeAll($ids);
    }

    /**
     * Gather candidates from every source, filter once, blend.
     *
     * @return array{sources: array<string, array<int|string, float>>, boosts: array<string, array<int|string, float>>, blended: array<int|string, float>, exploration: list<int|string>, weights: array<string, float>, boost_weights: array<string, float>}
     */
    private function collect(Context $context, int $limit, bool $trendingOnly): array
    {
        $feed = $context->feed;
        $sourceWeights = $feed->weighted('sources');
        $explorationClasses = $trendingOnly ? [] : $feed->list('exploration_sources');

        if ($trendingOnly) {
            // Guests / fallback: only sources that do not need a user.
            $sourceWeights = $feed->weighted('trending_sources') ?: [Sources\TrendingSource::class => 1.0];
        }

        $results = [];
        foreach (array_unique(array_merge(array_keys($sourceWeights), $explorationClasses)) as $class) {
            try {
                /** @var CandidateSource $source */
                $source = $this->container->make($class);
                $results[$class] = $source->candidates($context, $limit);
            } catch (Throwable $e) {
                // A broken source must not break the feed.
                $this->report($feed, "source {$class}", $e);
                $results[$class] = [];
            }
        }

        $seen = array_flip($context->seen);
        $all = [];
        foreach ($results as $scores) {
            foreach ($scores as $id => $score) {
                if (! isset($seen[$id])) {
                    $all[$id] = true;
                }
            }
        }

        $allowed = array_flip($this->allowed($context, array_keys($all)));
        foreach ($results as $class => $scores) {
            $results[$class] = array_intersect_key($scores, $allowed);
        }

        $sourceResults = array_intersect_key($results, $sourceWeights);
        $blended = $this->blender->blend($sourceResults, $sourceWeights);

        $boostWeights = $trendingOnly ? [] : $feed->weighted('boosters');
        $boosts = [];
        if ($boostWeights !== [] && $blended !== []) {
            $candidateIds = array_keys($blended);
            foreach ($boostWeights as $class => $weight) {
                try {
                    /** @var Booster $booster */
                    $booster = $this->container->make($class);
                    $boosts[$class] = $booster->boost($context, $candidateIds);
                } catch (Throwable $e) {
                    $this->report($feed, "booster {$class}", $e);
                }
            }
            $blended = $this->blender->blend($sourceResults, $sourceWeights, $boosts, $boostWeights);
        }

        $exploration = [];
        foreach ($explorationClasses as $class) {
            foreach (array_keys($results[$class] ?? []) as $id) {
                $exploration[$id] = true;
            }
        }

        return [
            'sources' => $sourceResults,
            'boosts' => $boosts,
            'blended' => $blended,
            'exploration' => array_keys($exploration),
            'weights' => $sourceWeights,
            'boost_weights' => $boostWeights,
        ];
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function hintIfEmpty(FeedDefinition $feed, int $page, array $ids): void
    {
        // An empty first page is almost always setup (no scores, a filter that
        // hides everything, a missing card). Say where to look — at most hourly.
        if ($page === 1 && $ids === [] && $this->cache->once($feed, 'empty-hint', 3600)) {
            Log::notice("[recommender:{$feed->name}] served an empty first page. Run `php artisan recommender:doctor {$feed->name}` or open /recommender to see why.");
        }
    }

    /**
     * Write impressions after the response when serving HTTP, so the request
     * does not wait for the four statements; in the console (tests, jobs) they
     * run at once. Counting must never break serving.
     *
     * @param  list<int|string>  $ids
     */
    private function recordImpressions(FeedDefinition $feed, array $ids): void
    {
        if ($ids === [] || ! $feed->get('impressions')) {
            return;
        }

        $write = function () use ($feed, $ids): void {
            try {
                $this->scores->addImpressions($feed->name, $ids, CarbonImmutable::now());
            } catch (Throwable $e) {
                $this->report($feed, 'impressions', $e);
            }
        };

        if (! app()->runningInConsole() && $feed->get('impressions_after_response', true)) {
            app()->terminating($write);

            return;
        }

        $write();
    }

    /**
     * The feed's catalog tail, or null when it has no model or `tail` is off.
     */
    private function tail(FeedDefinition $feed): ?Eloquent\CatalogTail
    {
        $model = $feed->get('model');
        if (! $feed->get('tail', true) || ! is_string($model) || ! is_a($model, Model::class, true)) {
            return null;
        }
        $order = $feed->get('tail_order');

        return new Eloquent\CatalogTail($model, is_string($order) && $order !== '' ? $order : null);
    }

    /**
     * @param  list<int|string>  $unseen
     * @param  array<int|string, mixed>  $seenSet
     * @param  array<string, mixed>  $attributes
     * @return array{0: list<int|string>, 1: array<string, true>}  queue, tail ids added
     */
    private function appendTail(FeedDefinition $feed, int|string $userId, array $unseen, array $seenSet, array $attributes, int $need): array
    {
        $tail = $this->tail($feed);
        if ($tail === null || $need <= 0) {
            return [$unseen, []];
        }

        try {
            $context = new Context($feed, $userId, [], $attributes, CarbonImmutable::now());
            $more = $tail->ids($context, array_merge(Ids::normalizeAll(array_keys($seenSet)), $unseen), $need);
            // The scope already applied visibility; a custom filter class still gets its say.
            $before = count($unseen);
            $unseen = $feed->get('filter')
                ? $this->appendAllowed($feed, $userId, $unseen, $more, $seenSet, $attributes)
                : array_merge($unseen, $more);
        } catch (Throwable $e) {
            $this->report($feed, 'tail', $e);

            return [$unseen, []];
        }

        return [$unseen, array_fill_keys(array_map('strval', array_slice($unseen, $before)), true)];
    }

    /**
     * Append candidate ids that pass the feed filter and are not already queued.
     *
     * @param  list<int|string>  $unseen
     * @param  list<int|string>  $candidates
     * @param  array<int|string, mixed>  $exclude
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>
     */
    private function appendAllowed(
        FeedDefinition $feed,
        int|string $userId,
        array $unseen,
        array $candidates,
        array $exclude,
        array $attributes,
        ?int $limit = null,
    ): array {
        $have = array_flip($unseen);
        $pending = [];
        foreach ($candidates as $id) {
            if (isset($exclude[$id]) || isset($have[$id])) {
                continue;
            }
            $pending[] = $id;
            $have[$id] = true;
        }
        if ($pending === []) {
            return $unseen;
        }

        $context = new Context($feed, $userId, [], $attributes, CarbonImmutable::now());
        $allowed = array_flip($this->allowed($context, $pending));

        foreach ($pending as $id) {
            if ($limit !== null && count($unseen) >= $limit) {
                break;
            }
            if (isset($allowed[$id])) {
                $unseen[] = $id;
            }
        }

        return $unseen;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int|string>
     */
    private function allowed(Context $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $filter = $this->bindings->filter($context->feed);
        if ($filter === null) {
            return $ids;
        }

        return $filter->allowed($context, Ids::normalizeAll($ids));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: int|string|null, 1: array<string, mixed>}  user id, attributes (with 'user' when a model was given)
     */
    private function viewer(Authenticatable|int|string|null $user, array $attributes = []): array
    {
        if ($user instanceof Authenticatable) {
            $attributes['user'] ??= $user;
            $id = $user->getAuthIdentifier();

            return [is_int($id) || is_string($id) ? $id : null, $attributes];
        }

        return [$user, $attributes];
    }

    /**
     * @param  array<string, int>  $map  id => 0-based index
     */
    private static function position(array $map, int|string $id): ?int
    {
        return isset($map[(string) $id]) ? $map[(string) $id] + 1 : null;
    }

    private function report(FeedDefinition $feed, string $stage, Throwable $e): void
    {
        Log::warning("[recommender:{$feed->name}] {$stage} failed: {$e->getMessage()}", ['exception' => $e]);
    }
}
