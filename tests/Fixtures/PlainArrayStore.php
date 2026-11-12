<?php

namespace SolutionForest\Recommender\Tests\Fixtures;

use Illuminate\Contracts\Cache\Store;

/**
 * In-memory store that deliberately does **not** implement LockProvider,
 * so FeedCache must fall back to UnlockedBuildLock.
 */
final class PlainArrayStore implements Store
{
    /** @var array<string, mixed> */
    private array $storage = [];

    public function get($key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    public function many(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->storage[$key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1): int|bool
    {
        $this->storage[$key] = ((int) ($this->storage[$key] ?? 0)) + $value;

        return $this->storage[$key];
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function touch($key, $seconds): bool
    {
        return array_key_exists($key, $this->storage);
    }

    public function forget($key): bool
    {
        unset($this->storage[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->storage = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
