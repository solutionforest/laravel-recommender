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
}
