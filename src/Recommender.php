<?php

namespace SolutionForest\Recommender;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Contracts\DiversityGroups;
use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Jobs\BuildFeed;
use SolutionForest\Recommender\Scoring\Blender;
use SolutionForest\Recommender\Scoring\Diversifier;
use SolutionForest\Recommender\Scoring\Explorer;
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
     * @param  array<string, mixed>  $attributes  passed to sources/filters via Context
     */
    public function page(string $feedName, int|string|null $userId, int $page = 1, ?int $perPage = null, array $attributes = []): FeedPage
    {
        $feed = $this->feed($feedName);
        $page = max(1, $page);
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
            $have = array_flip($unseen);
            foreach ($this->trending($feedName, $attributes) as $id) {
                if (! isset($seenSet[$id]) && ! isset($have[$id])) {
                    $unseen[] = $id;
                    $have[$id] = true;
                }
            }
            if ($ids === []) {
                $origin = 'trending';
            }
        }

        if ($page === 1 && count($unseen) < $perPage) {
            // Never show an empty first screen: allow previously seen items.
            $have = array_flip($unseen);
            foreach (array_merge($ids, $this->trending($feedName, $attributes)) as $id) {
                if (count($unseen) >= $perPage) {
                    break;
                }
                if (! isset($have[$id])) {
                    $unseen[] = $id;
                    $have[$id] = true;
                }
            }
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
    public function build(string $feedName, int|string $userId, ?array $seen = null, array $attributes = []): array
    {
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
    public function warm(string $feedName, int|string $userId): void
    {
        $this->feed($feedName);
        BuildFeed::dispatch($feedName, $userId);
    }

    /** Drop a cached card, e.g. after the item was edited or hidden. */
    public function forgetCard(string $feedName, int|string $id): void
    {
        $this->cache->forgetCard($this->feed($feedName), $id);
    }

    /** Forget a user's list and seen-set (e.g. "reset my recommendations"). */
    public function forgetUser(string $feedName, int|string $userId): void
    {
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
     * @return list<array{id: int|string, score: float, sources: array<string, float>}>
     */
    public function explain(string $feedName, int|string|null $userId, int $limit = 20): array
    {
        $feed = $this->feed($feedName);
        $context = new Context($feed, $userId, [], [], CarbonImmutable::now());
        $parts = $this->collect($context, $feed->int('source_limit', 200), $userId === null);

        $out = [];
        foreach (array_slice($parts['blended'], 0, $limit, true) as $id => $score) {
            $sources = [];
            foreach ($parts['sources'] as $class => $scores) {
                if (isset($scores[$id])) {
                    $sources[class_basename($class)] = round($scores[$id], 4);
                }
            }
            foreach ($parts['boosts'] as $class => $values) {
                if (isset($values[$id])) {
                    $sources[class_basename($class)] = round($values[$id], 4);
                }
            }
            $out[] = ['id' => $id, 'score' => round($score, 4), 'sources' => $sources];
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
        $class = $feed->get('cards');
        if (! $class) {
            throw new InvalidArgumentException("Feed [{$feed->name}] has no 'cards' transformer configured.");
        }
        /** @var CardTransformer $transformer */
        $transformer = $this->container->make($class);

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
        $feed = $context->feed;
        $parts = $this->collect($context, $feed->int('source_limit', 200), $trendingOnly);

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
        $groupsClass = $feed->get('groups');
        if ($diversity !== [] && $groupsClass && $ids !== []) {
            /** @var DiversityGroups $groups */
            $groups = $this->container->make($groupsClass);
            $ids = $this->diversifier->apply($ids, $groups->groups($ids), $diversity, $feed->int('page_size', 12));
        }

        return Ids::normalizeAll($ids);
    }

    /**
     * Gather candidates from every source, filter once, blend.
     *
     * @return array{sources: array<string, array<int|string, float>>, boosts: array<string, array<int|string, float>>, blended: array<int|string, float>, exploration: list<int|string>}
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
        ];
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
        $class = $context->feed->get('filter');
        if (! $class) {
            return $ids;
        }
        /** @var ItemFilter $filter */
        $filter = $this->container->make($class);

        return $filter->allowed($context, Ids::normalizeAll($ids));
    }

    private function report(FeedDefinition $feed, string $stage, Throwable $e): void
    {
        Log::warning("[recommender:{$feed->name}] {$stage} failed: {$e->getMessage()}", ['exception' => $e]);
    }
}
