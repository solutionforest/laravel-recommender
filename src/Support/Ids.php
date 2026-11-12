<?php

namespace SolutionForest\Recommender\Support;

/**
 * Item ids are stored as strings in the package tables; plain numeric ids
 * are handed back to the app as integers. Strings that only look numeric
 * ("007", "000123") stay strings, so zero-padded external ids survive.
 */
final class Ids
{
    public static function normalize(int|string $id): int|string
    {
        if (is_int($id)) {
            return $id;
        }

        $plain = ctype_digit($id) && strlen($id) < 19 && ($id === '0' || $id[0] !== '0');

        return $plain ? (int) $id : $id;
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
