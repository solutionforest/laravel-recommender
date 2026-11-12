<?php

namespace SolutionForest\Recommender\Support;

/**
 * Item ids are stored as strings in the package tables; numeric ids are
 * handed back to the app as integers.
 */
final class Ids
{
    public static function normalize(int|string $id): int|string
    {
        if (is_int($id)) {
            return $id;
        }

        return ctype_digit($id) && strlen($id) < 19 ? (int) $id : $id;
    }

    /**
     * @param  iterable<int|string>  $ids
     * @return list<int|string>
     */
    public static function normalizeAll(iterable $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[] = self::normalize($id);
        }

        return $out;
    }
}
