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

        // Package defaults < app 'defaults' < feed config. Merged here (not only
        // via mergeConfigFrom, which is shallow) so a published config file
        // that lists only a few defaults still gets every key.
        $defaults = array_replace(self::packageDefaults(), (array) config('recommender.defaults', []));

        return $this->definitions[$name] = FeedDefinition::make($name, $defaults, (array) $feeds[$name]);
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
        $page = max(1, $page ?? Paginator::resolveCurrentPage());
        $perPage = max(1, $perPage ?? $feed->int('page_size', 12));

        if ($userId === null || ! $this->enabled()) {
            return $this->trendingPage($feed, $page, $perPage, $attributes);
        }

        $seen = $this->cache->seen($feed, $userId);
        $seenSet = array_flip($seen);
        $origin = 'personal';

        $list = $this->cache->list($feed, $userId);
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

        [$cards, $servedIds, $consumed] = $this->render($feed, $unseen, $perPage);
        $this->cache->markSeen($feed, $userId, $consumed);

        $remaining = max(0, count($unseen) - count($consumed));

        return new FeedPage(
            items: array_values($cards),
            ids: $servedIds,
            page: $page,
            perPage: $perPage,
            hasMore: $remaining > 0,
            remaining: $remaining,
            origin: $origin,
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
        $seen ??= $this->cache->seen($feed, $userId);
        $context = new Context($feed, $userId, $seen, $attributes, CarbonImmutable::now());

        $ids = $this->rank($context, $feed->int('feed_size', 200));
        $this->cache->putList($feed, $userId, $ids);

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

                return [];
            }
        });
    }

    /**
     * Queue a background rebuild (e.g. on login / app open).
     */
    public function warm(string $feedName, Authenticatable|int|string $user): void
    {
        $this->feed($feedName);
        BuildFeed::dispatch($feedName, $this->viewer($user)[0] ?? throw new InvalidArgumentException('Cannot warm a feed for a user without an id.'));
    }

    /** Drop a cached card, e.g. after the item was edited or hidden. */
    public function forgetCard(string $feedName, int|string $id): void
    {
        $this->cache->forgetCard($this->feed($feedName), $id);
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

    /** Forget a user's list and seen-set (e.g. "reset my recommendations"). */
    public function forgetUser(string $feedName, Authenticatable|int|string $user): void
    {
        $userId = $this->viewer($user)[0] ?? throw new InvalidArgumentException('Cannot forget a user without an id.');
        $feed = $this->feed($feedName);
        $this->cache->forgetList($feed, $userId);
        $this->cache->forgetSeen($feed, $userId);
    }

    public function forgetTrending(string $feedName): void
    {
        $this->cache->forgetTrending($this->feed($feedName));
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
     * @return list<array{id: int|string, score: float, sources: array<string, float>, contributions: array<string, float>, final: ?int, cached: ?int, exploration: bool}>
     */
    public function explain(string $feedName, Authenticatable|int|string|null $user, int $limit = 20): array
    {
        $userId = $this->viewer($user)[0];
        $feed = $this->feed($feedName);
        $context = new Context($feed, $userId, [], [], CarbonImmutable::now());
        $parts = $this->collect($context, $feed->int('source_limit', 200), $userId === null);

        $final = array_flip(array_map('strval', $this->order($context, $parts, $feed->int('feed_size', 200), $userId === null)));
        $cachedList = $userId === null
            ? $this->cache->peekTrending($feed)
            : ($this->cache->list($feed, $userId)['ids'] ?? null);
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
        $lock = $this->cache->lock($feed, $userId);

        try {
            return $lock->block($feed->int('build_lock_wait', 2), function () use ($feed, $userId, $seen, $attributes) {
                return $this->build($feed->name, $userId, $seen, $attributes);
            });
        } catch (LockTimeoutException) {
            // Someone else is building: use whatever is there now.
            return $this->cache->list($feed, $userId)['ids'] ?? null;
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

        [$cards, $servedIds, $consumed] = $this->render($feed, $window, $perPage);
        $remaining = max(0, count($window) - count($consumed));

        return new FeedPage(
            items: array_values($cards),
            ids: $servedIds,
            page: $page,
            perPage: $perPage,
            hasMore: $remaining > 0,
            remaining: $remaining,
            origin: 'trending',
        );
    }

    /**
     * Take up to $perPage renderable cards from the front of $ids.
     *
     * @param  list<int|string>  $ids
     * @return array{0: array<int|string, array<string, mixed>>, 1: list<int|string>, 2: list<int|string>}
     *         cards, ids of the cards, ids consumed from the list (incl. ones that failed to render)
     */
    private function render(FeedDefinition $feed, array $ids, int $perPage): array
    {
        $transformer = $this->bindings->cards($feed);

        $cards = [];
        $consumed = [];
        $offset = 0;
        // Items can disappear between build and render (hidden, deleted);
        // fetch a little more until the page is full. Bounded to 3 rounds.
        for ($round = 0; $round < 3 && count($cards) < $perPage && $offset < count($ids); $round++) {
            $need = $perPage - count($cards);
            $batch = array_slice($ids, $offset, $need + ($round === 0 ? 0 : $need));
            $offset += count($batch);
            $rendered = $this->cache->cards($feed, $batch, fn (array $missing) => $transformer->cards($missing));
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
