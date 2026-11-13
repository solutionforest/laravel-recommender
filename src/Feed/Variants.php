<?php

namespace SolutionForest\Recommender\Feed;

use SolutionForest\Recommender\Support\FeedDefinition;

/**
 * Which cache copy a request reads, from its attributes:
 * `list_vary_by` (ranked + trending lists), `seen_vary_by` (seen list) and
 * `cards_vary_by` (cards). Null = the shared copy.
 */
final class Variants
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function list(FeedDefinition $feed, array $attributes): ?string
    {
        return FeedCache::variant($feed->list('list_vary_by'), $attributes);
    }

    /**
     * Defaults to `tenant` when it is in `list_vary_by` (tenants have disjoint
     * items); `device` / `locale` stay shared so a user sees no repeats
     * across devices. Override with `seen_vary_by`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function seen(FeedDefinition $feed, array $attributes): ?string
    {
        $names = $feed->get('seen_vary_by') !== null
            ? $feed->list('seen_vary_by')
            : array_values(array_intersect($feed->list('list_vary_by'), ['tenant']));

        return FeedCache::variant($names, $attributes);
    }

    /**
     * `locale` falls back to the app locale.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: ?string, 1: array<string, mixed>}  variant (null = shared cards), attributes
     */
    public static function cards(FeedDefinition $feed, array $attributes): array
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
}
