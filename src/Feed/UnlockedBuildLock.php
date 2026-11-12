<?php

namespace SolutionForest\Recommender\Feed;

use Illuminate\Contracts\Cache\Lock;

/**
 * No-op lock for cache drivers that cannot provide atomic locks
 * (e.g. database without a lock table, some file setups).
 *
 * Tradeoff: two concurrent rebuilds for the same user may race; the last
 * write wins. Fine for local labs; use Redis (or any LockProvider store) in
 * production so {@see FeedCache::lock()} returns a real lock.
 */
final class UnlockedBuildLock implements Lock
{
    public function get($callback = null): mixed
    {
        return $callback !== null ? $callback() : true;
    }

    public function block($seconds, $callback = null): mixed
    {
        return $callback !== null ? $callback() : true;
    }

    public function release(): bool
    {
        return true;
    }

    public function owner(): string
    {
        return '';
    }

    public function forceRelease(): void
    {
    }
}
