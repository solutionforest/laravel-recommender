<?php

namespace SolutionForest\Recommender\Scoring;

use DateTimeInterface;

final class Math
{
    /** Reference point for time decay (keeps exponents small). */
    private const EPOCH = 1577836800; // 2020-01-01T00:00:00Z

    /**
     * log(e^a + e^b) without overflow; null means "no value yet" (log 0).
     */
    public static function logAddExp(?float $a, float $b): float
    {
        if ($a === null) {
            return $b;
        }
        $max = max($a, $b);

        return $max + log(exp($a - $max) + exp($b - $max));
    }

    /**
     * Time-decayed popularity in log space.
     *
     * The decayed score of an item at time T is Σ w·e^{-λ(T - t)}. Ranking by
     * that is the same as ranking by log Σ w·e^{λt}, which does not depend on
     * T, so it can be stored once and updated incrementally with logAddExp.
     */
    public static function hotTerm(float $weight, DateTimeInterface $at, float $halfLifeDays): float
    {
        $lambda = log(2) / max(0.01, $halfLifeDays);
        $days = ($at->getTimestamp() - self::EPOCH) / 86400;

        return log(max($weight, 1e-9)) + $lambda * $days;
    }

    /**
     * Lower bound of the Wilson score interval (95%): a "quality" estimate
     * that does not over-reward items with very few exposures.
     */
    public static function wilson(int $positive, int $exposure, float $z = 1.96): float
    {
        $n = max($exposure, $positive);
        if ($n <= 0) {
            return 0.0;
        }
        $p = $positive / $n;
        $z2 = $z * $z;

        return max(0.0, ($p + $z2 / (2 * $n) - $z * sqrt(($p * (1 - $p) + $z2 / (4 * $n)) / $n)) / (1 + $z2 / $n));
    }

    /**
     * Score from list position (0 = best). Use when query order alone matters
     * (soonest event, nearest district match, …).
     *
     * Values: 1, ½, ⅓, ¼, … — steep drop after the first few rows.
     */
    public static function fromPosition(int $position): float
    {
        return 1.0 / (1 + max(0, $position));
    }

    /**
     * Preference strength × soft position decay. Use when items share an
     * affinity (favorite type/host weight) but you still want a slight nudge
     * toward earlier rows in the query.
     *
     * `$step = 0.05` ≈ almost flat across the page; `1.0` ≈ {@see fromPosition}.
     */
    public static function fromAffinity(float $affinity, int $position = 0, float $step = 0.05): float
    {
        return max(0.0, $affinity) / (1 + max(0, $position) * max(0.0, $step));
    }

    /**
     * Exponential age decay — “newer is better”. After `$halfLife` units the
     * score is ≈ 0.37. Pass age in hours or days; keep the same unit as half-life.
     */
    public static function fromAge(float $age, float $halfLife): float
    {
        return exp(-max(0.0, $age) / max(0.01, $halfLife));
    }

    /**
     * Scale so the best value in the batch is 1.0 (empty → []). Useful in
     * boosters before returning to the blender.
     *
     * @param  array<int|string, float>  $scores
     * @return array<int|string, float>
     */
    public static function normalizeMax(array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        $max = max($scores);

        return $max > 0 ? array_map(fn (float $v) => $v / $max, $scores) : $scores;
    }

    /**
     * Score an already-ordered list of candidates.
     *
     * - No `$weight`: row 0 wins (`fromPosition`).
     * - With `$weight($item)`: preference × soft order (`fromAffinity`).
     *
     * Items may be models/objects with `id`, arrays with `id`, or bare ids.
     *
     * @param  iterable<mixed>  $items
     * @param  (callable(mixed): float)|null  $weight
     * @return array<int|string, float>
     */
    public static function scoreInOrder(iterable $items, ?callable $weight = null, float $step = 0.05): array
    {
        $scores = [];
        $position = 0;

        foreach ($items as $item) {
            $id = self::itemId($item);
            $scores[$id] = $weight === null
                ? self::fromPosition($position)
                : self::fromAffinity((float) $weight($item), $position, $step);
            $position++;
        }

        return $scores;
    }

    /**
     * Soft-boost ids whose attribute appears in a preferred list (best first).
     *
     * Example: preferred type ids `[2, 1, 5]` → events of type 2 score 1.0, type 1 → 0.5, …
     *
     * @param  list<int|string>  $ids
     * @param  list<int|string>  $preferred  ordered keys (favorite first)
     * @param  array<int|string, int|string>  $attributeById  item id => attribute (type_id, host_id, …)
     * @return array<int|string, float>
     */
    public static function preferRanked(array $ids, array $preferred, array $attributeById): array
    {
        $rank = [];
        foreach (array_values($preferred) as $position => $key) {
            $rank[(string) $key] = $position;
        }

        $out = [];
        foreach ($ids as $id) {
            $key = $attributeById[$id] ?? null;
            if ($key !== null && array_key_exists((string) $key, $rank)) {
                $out[$id] = self::fromPosition($rank[(string) $key]);
            }
        }

        return $out;
    }

    private static function itemId(mixed $item): int|string
    {
        if (is_object($item)) {
            return $item->id;
        }

        if (is_array($item)) {
            return $item['id'];
        }

        return $item;
    }
}
