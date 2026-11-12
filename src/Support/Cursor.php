<?php

namespace SolutionForest\Recommender\Support;

/**
 * Opaque "next page" token for infinite scroll. Clients pass it back as
 * `?cursor=…` instead of a page number.
 *
 * It means "the next page of this feed for this user", not a frozen list:
 * the package pages by the per-user seen-set (shared by all their devices),
 * so a cursor never repeats items and never goes stale. It carries the feed
 * name, so a cursor from one feed is ignored by another.
 */
final class Cursor
{
    public static function encode(int $page, ?string $feed = null): string
    {
        $data = $feed === null ? ['p' => $page] : ['p' => $page, 'f' => $feed];

        return rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    }

    /**
     * The page in $cursor, or null when it is invalid or belongs to another feed.
     */
    public static function decode(?string $cursor, ?string $feed = null): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data)) {
            return null;
        }
        if ($feed !== null && isset($data['f']) && $data['f'] !== $feed) {
            return null; // a cursor from another feed: start at page 1
        }
        $page = $data['p'] ?? null;

        return is_int($page) && $page >= 1 ? $page : null;
    }

    /** The page requested via `?cursor=` on the current request, if any. */
    public static function currentPage(?string $feed = null): ?int
    {
        if (! app()->bound('request')) {
            return null;
        }
        $cursor = app('request')->query('cursor');

        return self::decode(is_string($cursor) ? $cursor : null, $feed);
    }

    public static function requested(): bool
    {
        return app()->bound('request') && is_string(app('request')->query('cursor'));
    }
}
