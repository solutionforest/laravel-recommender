<?php

namespace SolutionForest\Recommender\Feed;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use SolutionForest\Recommender\Ranking\Ranker;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Report;
use Throwable;

/**
 * Builds and caches ranked lists: one per user (personal) and one shared
 * (trending — guests, the off switch and the fallback).
 */
final class ListBuilder
{
    public function __construct(
        private readonly FeedCache $cache,
        private readonly Ranker $ranker,
    ) {
    }

    /**
     * @param  list<int|string>  $seen
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>
     */
    public function personal(FeedDefinition $feed, int|string $userId, array $seen, array $attributes): array
    {
        $context = new Context($feed, $userId, $seen, $attributes, CarbonImmutable::now());
        $ids = $this->ranker->rank($context, $feed->int('feed_size', 200));
        $this->cache->putList($feed, $userId, $ids, Variants::list($feed, $attributes));

        return $ids;
    }

    /**
     * Build under the user's lock so concurrent requests build once.
     *
     * @param  list<int|string>  $seen
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>|null  null when another request is building and nothing is cached
     */
    public function personalWithLock(FeedDefinition $feed, int|string $userId, array $seen, array $attributes): ?array
    {
        $variant = Variants::list($feed, $attributes);

        try {
            return $this->cache->lock($feed, $userId, $variant)->block(
                $feed->int('build_lock_wait', 2),
                fn () => $this->personal($feed, $userId, $seen, $attributes),
            );
        } catch (LockTimeoutException) {
            return $this->cache->list($feed, $userId, $variant)['ids'] ?? null; // someone else is building
        } catch (Throwable $e) {
            Report::failure($feed, 'build', $e);

            return null;
        }
    }

    /**
     * Built at most once per trending_ttl for everyone (per list variant).
     *
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>
     */
    public function trending(FeedDefinition $feed, array $attributes = []): array
    {
        return $this->cache->trending($feed, function () use ($feed, $attributes) {
            try {
                $context = new Context($feed, null, [], $attributes, CarbonImmutable::now());

                return $this->ranker->rank($context, $feed->int('trending_size', 200), trendingOnly: true);
            } catch (Throwable $e) {
                Report::failure($feed, 'trending', $e);

                return null; // not cached: retried on the next request
            }
        }, Variants::list($feed, $attributes));
    }
}
