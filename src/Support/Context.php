<?php

namespace SolutionForest\Recommender\Support;

use Carbon\CarbonImmutable;

/**
 * Request bag passed into every CandidateSource / Booster / ItemFilter / DiversityGroups call.
 *
 * Think of it as “who are we ranking for, right now?” — not an HTTP request object.
 *
 * | Property | Meaning |
 * |---|---|
 * | `$userId` | Viewer id from `Recommender::page($feed, $userId, …)`. `null` = guest → trending. |
 * | `$feed` | This feed’s config (`FeedDefinition`) — weights, table hooks, custom keys. |
 * | `$seen` | Item ids already shown to this user (avoid repeats). |
 * | `$attributes` | Optional extras you passed via `page(…, attributes: ['user' => $user])`. |
 * | `now()` | Clock for “upcoming / last 7 days” queries (injectable in tests). |
 *
 * Typical use in a source:
 *
 * ```php
 * if (! $context->userId) {
 *     return []; // guests have no personal rules
 * }
 *
 * $user = $context->attribute('user'); // optional Eloquent model you passed in
 * ```
 */
final class Context
{
    /**
     * @param  list<int|string>  $seen  ids already served to this user
     * @param  array<string, mixed>  $attributes  app-supplied extras (e.g. the User model)
     */
    public function __construct(
        public readonly FeedDefinition $feed,
        public readonly int|string|null $userId,
        public readonly array $seen = [],
        public readonly array $attributes = [],
        public readonly ?CarbonImmutable $now = null,
    ) {
    }

    /** True when ranking the shared guest / trending list (`$userId` is null). */
    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /** Read an extra from `Recommender::page(…, attributes: […])`. */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** “Now” for time filters — fixed in tests when Context is built with `$now`. */
    public function now(): CarbonImmutable
    {
        return $this->now ?? CarbonImmutable::now();
    }
}
