<?php

namespace SolutionForest\Recommender\Scoring;

/**
 * Mixes randomness into a ranking, once per list build.
 *
 *   final = (1 - j) · score / max(score) + j · U(0, 1)
 *
 * j = 0 keeps the ranking unchanged; j = 1 is a uniform random order of the
 * candidates (the classic "ORDER BY RAND()" feel, but computed in PHP once
 * per build instead of by the database on every request). Values in between
 * keep the best items near the top while varying the order between builds.
 * The order is stable while the cached list lives, so paging never repeats.
 */
final class Jitter
{
    /** @var callable(): float */
    private $random;

    /**
     * @param  (callable(): float)|null  $random  returns a float in [0, 1); injectable for tests
     */
    public function __construct(?callable $random = null)
    {
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * @param  array<int|string, float>  $scores  id => score, best first
     * @return array<int|string, float> id => mixed score, best first
     */
    public function apply(array $scores, float $jitter): array
    {
        $jitter = min(1.0, max(0.0, $jitter));
        if ($jitter === 0.0 || $scores === []) {
            return $scores;
        }

        $max = max($scores);
        $out = [];
        foreach ($scores as $id => $score) {
            $normalised = $max > 0 ? $score / $max : 0.0;
            $out[$id] = (1 - $jitter) * $normalised + $jitter * ($this->random)();
        }
        arsort($out);

        return $out;
    }
}
