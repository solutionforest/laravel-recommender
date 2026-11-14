<?php

namespace SolutionForest\Recommender\Support;

use Stringable;

/**
 * Typed reads of untyped values: config, cache entries and database rows
 * (`stdClass` columns) are `mixed`. Same results as a plain PHP cast for
 * the values those sources really hold (numeric strings, ints, floats,
 * null); anything else falls back to the default instead of a surprise.
 */
final class Value
{
    public static function int(mixed $value, int $default = 0): int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value), is_bool($value) => (int) $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => $default,
        };
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return match (true) {
            is_float($value) => $value,
            is_int($value), is_bool($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => $default,
        };
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value), $value instanceof Stringable => (string) $value,
            default => $default,
        };
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    /** An item / user id as stored (int or string); null for anything else. */
    public static function id(mixed $value): int|string|null
    {
        return match (true) {
            is_int($value), is_string($value) => $value,
            is_float($value) => (int) $value,
            $value instanceof Stringable => (string) $value,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
