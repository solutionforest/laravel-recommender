<?php

namespace SolutionForest\Recommender\Support;

use Carbon\CarbonImmutable;

/**
 * Everything a source/filter/booster needs to know about the current build.
 */
final class Context
{
    /**
     * @param  list<int|string>  $seen  ids already served to this user
     * @param  array<string, mixed>  $attributes  app supplied extras (e.g. the User model)
     */
    public function __construct(
        public readonly FeedDefinition $feed,
        public readonly int|string|null $userId,
        public readonly array $seen = [],
        public readonly array $attributes = [],
        public readonly ?CarbonImmutable $now = null,
    ) {
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function now(): CarbonImmutable
    {
        return $this->now ?? CarbonImmutable::now();
    }
}
