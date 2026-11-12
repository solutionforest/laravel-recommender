<?php

namespace SolutionForest\Recommender\Feed;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Ids;

/**
 * Every per-user / per-item value the package keeps lives here, in the cache
 * store (Redis in production). All keys share one prefix and have a TTL.
 *
 *   {prefix}:{feed}:list:{user}  "built_at|id,id,..."          (2 x feed_ttl)
 *   {prefix}:{feed}:seen:{user}  "id,id,..." already served    (seen_ttl)
 *   {prefix}:{feed}:card:{id}    rendered card                 (card_ttl)
 *   {prefix}:{feed}:trending     shared fallback list          (trending_ttl)
 *   {prefix}:{feed}:lock:{user}  build lock                    (build_lock_seconds)
 */
final class FeedCache
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    /**
     * @return array{ids: list<int|string>, built_at: int}|null
     */
    public function list(FeedDefinition $feed, int|string $userId): ?array
    {
        $value = $this->store()->get($this->key($feed, "list:{$userId}"));

        // Stored packed as "built_at|id,id,..." (about 7 bytes per id).
        if (is_string($value) && str_contains($value, '|')) {
            [$builtAt, $ids] = explode('|', $value, 2);

            return ['ids' => self::unpack($ids), 'built_at' => (int) $builtAt];
        }

        return is_array($value) && isset($value['ids'], $value['built_at']) ? $value : null;
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function putList(FeedDefinition $feed, int|string $userId, array $ids): void
    {
        // Keep the list around twice as long as it is "fresh" so a failed
        // rebuild can still serve the previous one.
        $this->store()->put(
            $this->key($feed, "list:{$userId}"),
            time().'|'.self::pack($ids),
            $feed->int('feed_ttl', 1800) * 2,
        );
    }

    public function forgetList(FeedDefinition $feed, int|string $userId): void
    {
        $this->store()->forget($this->key($feed, "list:{$userId}"));
    }

    /**
     * @return list<int|string>
     */
    public function seen(FeedDefinition $feed, int|string $userId): array
    {
        $value = $this->store()->get($this->key($feed, "seen:{$userId}"));

        if (is_string($value)) {
            return self::unpack($value);
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function markSeen(FeedDefinition $feed, int|string $userId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $seen = $this->seen($feed, $userId);
        $seen = array_values(array_unique(array_merge($seen, $ids), SORT_REGULAR));
        $max = $feed->int('seen_max', 500);
        if (count($seen) > $max) {
            $seen = array_slice($seen, -$max);
        }
        $this->store()->put($this->key($feed, "seen:{$userId}"), self::pack($seen), $feed->int('seen_ttl', 259200));
    }

    /**
     * Ids as one comma separated string: far smaller than a serialized PHP
     * array (~7 vs ~20 bytes per id). Item ids must not contain commas.
     *
     * @param  list<int|string>  $ids
     */
    public static function pack(array $ids): string
    {
        return implode(',', $ids);
    }

    /**
     * @return list<int|string>
     */
    public static function unpack(string $packed): array
    {
        return $packed === '' ? [] : Ids::normalizeAll(explode(',', $packed));
    }

    public function forgetSeen(FeedDefinition $feed, int|string $userId): void
    {
        $this->store()->forget($this->key($feed, "seen:{$userId}"));
    }

    /**
     * Cards for $ids in the same order; misses are rendered by $render in one
     * batch and cached. Ids the renderer does not return are dropped.
     *
     * @param  list<int|string>  $ids
     * @param  callable(list<int|string>): array<int|string, array<string, mixed>>  $render
     * @return array<int|string, array<string, mixed>>
     */
    public function cards(FeedDefinition $feed, array $ids, callable $render): array
    {
        if ($ids === []) {
            return [];
        }

        $keys = [];
        foreach ($ids as $id) {
            $keys[$this->key($feed, "card:{$id}")] = $id;
        }

        $cached = $this->store()->many(array_keys($keys));
        $found = [];
        $missing = [];
        foreach ($keys as $key => $id) {
            if (is_array($cached[$key] ?? null)) {
                $found[$id] = $cached[$key];
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $rendered = $render($missing);
            $toCache = [];
            foreach ($rendered as $id => $card) {
                $found[$id] = $card;
                $toCache[$this->key($feed, "card:{$id}")] = $card;
            }
            if ($toCache !== []) {
                $this->store()->putMany($toCache, $feed->int('card_ttl', 3600));
            }
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $ordered[$id] = $found[$id];
            }
        }

        return $ordered;
    }

    public function forgetCard(FeedDefinition $feed, int|string $id): void
    {
        $this->store()->forget($this->key($feed, "card:{$id}"));
    }

    /**
     * @param  callable(): list<int|string>  $build
     * @return list<int|string>
     */
    public function trending(FeedDefinition $feed, callable $build): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->store()->remember(
            $this->key($feed, 'trending'),
            $feed->int('trending_ttl', 600),
            static fn () => $build(),
        );

        return $ids;
    }

    public function forgetTrending(FeedDefinition $feed): void
    {
        $this->store()->forget($this->key($feed, 'trending'));
    }

    public function lock(FeedDefinition $feed, int|string $userId): Lock
    {
        $store = $this->store();
        $seconds = $feed->int('build_lock_seconds', 10);
        $name = $this->key($feed, "lock:{$userId}");

        if ($store instanceof LockProvider) {
            return $store->lock($name, $seconds);
        }

        // Array / file drivers expose locks on the underlying store, not the repository.
        if (method_exists($store, 'getStore')) {
            $inner = $store->getStore();
            if ($inner instanceof LockProvider) {
                return $inner->lock($name, $seconds);
            }
        }

        throw new RuntimeException('Recommender cache store must support locks (use Redis in production).');
    }

    public function key(FeedDefinition $feed, string $suffix): string
    {
        return config('recommender.prefix', 'rec').":{$feed->name}:{$suffix}";
    }

    private function store(): Repository
    {
        return $this->cache->store(config('recommender.cache_store'));
    }
}
