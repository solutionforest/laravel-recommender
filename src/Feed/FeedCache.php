<?php

namespace SolutionForest\Recommender\Feed;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Ids;

/**
 * Every per-user / per-item value the package keeps lives here, in the cache
 * store (redis recommended; database / array / file also work). All keys share one prefix and have a TTL.
 *
 *   {prefix}:{feed}:list:{user}  "built_at|id,id,..."          (2 x feed_ttl)
 *   {prefix}:{feed}:seen:{user}  "id,id,..." already served    (seen_ttl)
 *   {prefix}:{feed}:card:{id}    rendered card                 (card_ttl)
 *   {prefix}:{feed}:trending     shared fallback list          (trending_ttl)
 *   {prefix}:{feed}:lock:{user}  build lock                    (build_lock_seconds)
 *
 * With `list_vary_by` / `seen_vary_by` the list, seen, trending and lock keys
 * get a `:v{hash}` suffix, one per attribute value (e.g. per tenant).
 */
final class FeedCache
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    /**
     * @return array{ids: list<int|string>, built_at: int}|null
     */
    public function list(FeedDefinition $feed, int|string $userId, ?string $variant = null): ?array
    {
        $value = $this->store()->get($this->key($feed, "list:{$userId}".self::suffix($variant)));

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
    public function putList(FeedDefinition $feed, int|string $userId, array $ids, ?string $variant = null): void
    {
        // Keep the list around twice as long as it is "fresh" so a failed
        // rebuild can still serve the previous one.
        $this->store()->put(
            $this->key($feed, "list:{$userId}".self::suffix($variant)),
            time().'|'.self::pack($ids),
            $feed->int('feed_ttl', 1800) * 2,
        );
    }

    public function forgetList(FeedDefinition $feed, int|string $userId, ?string $variant = null): void
    {
        $this->store()->forget($this->key($feed, "list:{$userId}".self::suffix($variant)));
    }

    /**
     * @return list<int|string>
     */
    public function seen(FeedDefinition $feed, int|string $userId, ?string $variant = null): array
    {
        $value = $this->store()->get($this->key($feed, "seen:{$userId}".self::suffix($variant)));

        if (is_string($value)) {
            return self::unpack($value);
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Add $ids to the user's seen-set and return the ones that were not in it
     * yet (so a caller can count "first time shown" exactly once, even when
     * two requests race). The read-merge-write runs under a short per-user
     * lock where the cache store has locks, so parallel requests (two tabs,
     * a retry) cannot overwrite each other's additions.
     *
     * @param  list<int|string>  $ids
     * @return list<int|string>
     */
    public function markSeen(FeedDefinition $feed, int|string $userId, array $ids, ?string $variant = null): array
    {
        if ($ids === []) {
            return [];
        }

        $write = function () use ($feed, $userId, $ids, $variant): array {
            $seen = $this->seen($feed, $userId, $variant);
            $known = array_flip(array_map('strval', $seen));
            $added = [];
            foreach ($ids as $id) {
                if (! isset($known[(string) $id])) {
                    $known[(string) $id] = true;
                    $added[] = $id;
                }
            }
            $seen = array_values(array_unique(array_merge($seen, $ids), SORT_REGULAR));
            $max = $feed->int('seen_max', 200);
            if (count($seen) > $max) {
                $seen = array_slice($seen, -$max);
            }
            $this->store()->put($this->key($feed, "seen:{$userId}".self::suffix($variant)), self::pack($seen), $feed->int('seen_ttl', 259200));

            return $added;
        };

        $provider = $this->lockProvider();
        if ($provider === null) {
            return $write();
        }

        try {
            return $provider->lock($this->key($feed, "seenlock:{$userId}".self::suffix($variant)), 5)->block(2, $write);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return $write(); // best effort: never fail a page over a busy lock
        }
    }

    /**
     * Ids as one comma separated string: far smaller than a serialized PHP
     * array (~7 vs ~20 bytes per id). Any id may be used: `%` and `,` inside
     * an id are percent-encoded, so plain ids keep the compact format.
     *
     * @param  list<int|string>  $ids
     */
    public static function pack(array $ids): string
    {
        $out = [];
        foreach ($ids as $id) {
            $out[] = is_int($id) ? (string) $id : strtr($id, ['%' => '%25', ',' => '%2C']);
        }

        return implode(',', $out);
    }

    /**
     * @return list<int|string>
     */
    public static function unpack(string $packed): array
    {
        if ($packed === '') {
            return [];
        }

        $ids = explode(',', $packed);
        if (str_contains($packed, '%')) {
            $ids = array_map(fn (string $id) => strtr($id, ['%2C' => ',', '%25' => '%']), $ids);
        }

        return Ids::normalizeAll($ids);
    }

    public function forgetSeen(FeedDefinition $feed, int|string $userId, ?string $variant = null): void
    {
        $this->store()->forget($this->key($feed, "seen:{$userId}".self::suffix($variant)));
    }

    /**
     * Cards for $ids in the same order; misses are rendered by $render in one
     * batch and cached. Ids the renderer does not return are dropped.
     *
     * @param  list<int|string>  $ids
     * @param  callable(list<int|string>): array<int|string, array<string, mixed>>  $render
     * @return array<int|string, array<string, mixed>>
     */
    public function cards(FeedDefinition $feed, array $ids, callable $render, ?string $variant = null): array
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
            $value = $cached[$key] ?? null;
            if ($variant === null && is_array($value) && ! isset($value['__variants'])) {
                $found[$id] = $value;
            } elseif ($variant !== null && is_array($value['__variants'][$variant] ?? null)) {
                $found[$id] = $value['__variants'][$variant];
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $rendered = $render($missing);
            $toCache = [];
            foreach ($rendered as $id => $card) {
                $found[$id] = $card;
                $key = $this->key($feed, "card:{$id}");
                if ($variant === null) {
                    $toCache[$key] = $card;
                } else {
                    // All variants of an item share one key, so forgetCard()
                    // drops every language / tenant at once.
                    $existing = $cached[$key] ?? null;
                    $variants = is_array($existing) && is_array($existing['__variants'] ?? null) ? $existing['__variants'] : [];
                    $variants[$variant] = $card;
                    $toCache[$key] = ['__variants' => $variants];
                }
            }
            if ($toCache !== []) {
                try {
                    $this->store()->putMany($toCache, $feed->int('card_ttl', 3600));
                } catch (\Throwable $e) {
                    // Cards were rendered; failing to cache them must not fail the page.
                    Log::warning("[recommender:{$feed->name}] card cache write failed: {$e->getMessage()}");
                }
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
     * $build returns null when it failed: that is served as an empty list but
     * never cached; an empty list is cached for at most 15 seconds. Either way a recovered source is
     * picked up quickly instead of after `trending_ttl`.
     *
     * @param  callable(): (list<int|string>|null)  $build
     * @return list<int|string>
     */
    public function trending(FeedDefinition $feed, callable $build, ?string $variant = null): array
    {
        $key = $this->key($feed, 'trending'.self::suffix($variant));
        $cached = $this->store()->get($key);
        if (is_array($cached)) {
            return array_values($cached);
        }

        $ids = $build();
        if ($ids === null) {
            return [];
        }
        // An empty list is usually a source that failed (or a brand-new feed):
        // keep it only briefly so recovery is quick but a rebuild storm is not.
        $ttl = $feed->int('trending_ttl', 600);
        $this->store()->put($key, $ids, $ids === [] ? min($ttl, 15) : $ttl);

        return $ids;
    }

    /**
     * The cached trending list, without building it.
     *
     * @return list<int|string>|null
     */
    public function peekTrending(FeedDefinition $feed, ?string $variant = null): ?array
    {
        $value = $this->store()->get($this->key($feed, 'trending'.self::suffix($variant)));

        return is_array($value) ? array_values($value) : null;
    }

    /**
     * True the first time per $ttl seconds (cache add), e.g. to rate-limit a log line.
     */
    public function once(FeedDefinition $feed, string $name, int $ttl): bool
    {
        return $this->store()->add($this->key($feed, $name), 1, $ttl);
    }

    public function forgetTrending(FeedDefinition $feed, ?string $variant = null): void
    {
        $this->store()->forget($this->key($feed, 'trending'.self::suffix($variant)));
    }

    /** Whether the configured cache store can provide atomic locks. */
    public function supportsLocks(): bool
    {
        return $this->lockProvider() !== null;
    }

    public function lock(FeedDefinition $feed, int|string $userId, ?string $variant = null): Lock
    {
        $seconds = $feed->int('build_lock_seconds', 10);
        $name = $this->key($feed, "lock:{$userId}".self::suffix($variant));
        $provider = $this->lockProvider();

        if ($provider !== null) {
            return $provider->lock($name, $seconds);
        }

        // Tradeoff: stores with no LockProvider still serve feeds; concurrent rebuilds
        // for the same user may race (last write wins). Prefer redis or database+cache_locks.
        Log::debug('[recommender] cache store has no locks; building without a per-user lock', [
            'feed' => $feed->name,
            'user' => $userId,
        ]);

        return new UnlockedBuildLock;
    }

    private function lockProvider(): ?LockProvider
    {
        $store = $this->store();

        // Laravel 11+ Repository is not a LockProvider; locks live on the inner store
        // (Redis / database / array). Older wrappers may still implement LockProvider.
        if ($store instanceof LockProvider) {
            return $store;
        }

        if ($store instanceof CacheRepository) {
            $inner = $store->getStore();
            if ($inner instanceof LockProvider) {
                return $inner;
            }
        }

        return null;
    }

    /**
     * Short, stable id for the attribute values a feed varies its lists by
     * (`list_vary_by` / `seen_vary_by`); null when nothing is varied, which
     * keeps every key identical to a feed without the option.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $attributes
     */
    public static function variant(array $names, array $attributes): ?string
    {
        if ($names === []) {
            return null;
        }
        $values = [];
        foreach ($names as $name) {
            $value = $attributes[$name] ?? null;
            $values[$name] = is_scalar($value) || $value === null ? $value : (string) json_encode($value);
        }

        return substr(sha1((string) json_encode($values)), 0, 10);
    }

    private static function suffix(?string $variant): string
    {
        return $variant === null ? '' : ":v{$variant}";
    }

    public function key(FeedDefinition $feed, string $suffix): string
    {
        return config('recommender.prefix', 'rec').":{$feed->name}:{$suffix}";
    }

    /**
     * Delete this package's expired rows from a *database* cache store.
     * Laravel only removes an expired row when that same key is read again,
     * so lists of users who never return would otherwise stay forever.
     * Bulk chunks by key; returns 0 for Redis / file / array stores.
     */
    public function pruneExpired(int $chunk = 5000): int
    {
        $repository = $this->store();
        $store = $repository instanceof CacheRepository ? $repository->getStore() : null;
        if (! $store instanceof \Illuminate\Cache\DatabaseStore) {
            return 0;
        }

        $name = $this->storeName() ?? config('cache.default');
        $table = (string) (config("cache.stores.{$name}.table") ?: 'cache');
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $store->getPrefix().config('recommender.prefix', 'rec').':').'%';
        $db = $store->getConnection();
        $deleted = 0;

        do {
            $keys = $db->table($table)
                ->where('key', 'like', $like)
                ->where('expiration', '<', time())
                ->limit(max(1, $chunk))
                ->pluck('key')
                ->all();
            if ($keys !== []) {
                $deleted += $db->table($table)->whereIn('key', $keys)->delete();
            }
        } while (count($keys) === $chunk);

        return $deleted;
    }

    /** Resolved store name for this process (null = the app's default store). */
    private ?string $resolved = null;

    private bool $isResolved = false;

    private function store(): Repository
    {
        return $this->cache->store($this->storeName());
    }

    /**
     * The cache store the package uses. RECOMMENDER_CACHE_STORE wins; when it
     * is not set, Redis is used if the app has a working Redis store (best for
     * the per-user seen-list: fast, atomic locks, free expiry), otherwise the
     * app's default store. The probe result is remembered for an hour in the
     * default store, so an unreachable Redis costs at most one probe an hour.
     */
    public function storeName(): ?string
    {
        if ($this->isResolved) {
            return $this->resolved;
        }
        $this->isResolved = true;

        $configured = config('recommender.cache_store');
        if (is_string($configured) && $configured !== '' && $configured !== 'auto') {
            return $this->resolved = $configured;
        }

        $default = config('cache.default');
        if ($default === 'redis' || ! is_array(config('cache.stores.redis'))) {
            return $this->resolved = null;
        }

        try {
            $verdict = $this->cache->store()->remember('recommender:store-probe', 3600, function () {
                try {
                    $this->cache->store('redis')->get('recommender:probe');

                    return 'redis';
                } catch (\Throwable $e) {
                    Log::info('[recommender] Redis is configured but not reachable; using the default cache store. Set RECOMMENDER_CACHE_STORE to choose one.', ['exception' => $e->getMessage()]);

                    return 'default';
                }
            });
        } catch (\Throwable) {
            $verdict = 'default';
        }

        return $this->resolved = $verdict === 'redis' ? 'redis' : null;
    }
}
