<?php

namespace SolutionForest\Recommender\Support;

/**
 * Opaque "next page" token for infinite scroll. Clients pass it back as
 * `?cursor=…` instead of a page number; the package still pages by the
 * per-user seen-set, so the token stays tiny and never goes stale.
 */
final class Cursor
{
    public static function encode(int $page): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['p' => $page])), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        $page = is_array($data) ? ($data['p'] ?? null) : null;

        return is_int($page) && $page >= 1 ? $page : null;
    }

    /** The page requested via `?cursor=` on the current request, if any. */
    public static function currentPage(): ?int
    {
        if (! app()->bound('request')) {
            return null;
        }
        $cursor = app('request')->query('cursor');

        return self::decode(is_string($cursor) ? $cursor : null);
    }

    public static function requested(): bool
    {
        return app()->bound('request') && is_string(app('request')->query('cursor'));
    }
}
