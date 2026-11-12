<?php

namespace SolutionForest\Recommender\Scoring;

/**
 * Weighted sum of max-normalised source scores plus booster values.
 *
 * score(item) = Σ w_source · score_source(item) / max(score_source)
 *             + Σ w_booster · boost(item)
 */
final class Blender
{
    /**
     * @param  array<string, array<int|string, float>>  $sourceScores  source => [id => raw score]
     * @param  array<string, float>  $sourceWeights  source => weight
     * @param  array<string, array<int|string, float>>  $boosts  booster => [id => 0..1]
     * @param  array<string, float>  $boostWeights  booster => weight
     * @return array<int|string, float> id => blended score, best first
     */
    public function blend(array $sourceScores, array $sourceWeights, array $boosts = [], array $boostWeights = []): array
    {
        $total = [];

        foreach ($sourceScores as $source => $scores) {
            $weight = $sourceWeights[$source] ?? 0.0;
            if ($weight <= 0 || $scores === []) {
                continue;
            }
            $max = max($scores);
            foreach ($scores as $id => $score) {
                $normalised = $max > 0 ? max(0.0, $score) / $max : 0.0;
                $total[$id] = ($total[$id] ?? 0.0) + $weight * $normalised;
            }
        }

        foreach ($boosts as $booster => $values) {
            $weight = $boostWeights[$booster] ?? 0.0;
            if ($weight <= 0) {
                continue;
            }
            foreach ($values as $id => $value) {
                if (array_key_exists($id, $total)) {
                    $total[$id] += $weight * min(1.0, max(0.0, $value));
                }
            }
        }

        // Stable sort: equal scores keep first-seen order.
        $order = array_flip(array_keys($total));
        uksort($total, function ($a, $b) use ($total, $order) {
            return [$total[$b], $order[$a]] <=> [$total[$a], $order[$b]];
        });

        return $total;
    }
}
