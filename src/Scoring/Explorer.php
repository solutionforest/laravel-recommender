<?php

namespace SolutionForest\Recommender\Scoring;

/**
 * Reserves a share of slots for exploration items (fresh / trending) so new
 * content gets exposure and the feed does not collapse onto old favourites.
 */
final class Explorer
{
    /**
     * @param  list<int|string>  $ranked
     * @param  list<int|string>  $pool  exploration ids, in the order they should be used
     * @return list<int|string>
     */
    public function interleave(array $ranked, array $pool, float $ratio): array
    {
        $inRanked = array_flip($ranked);
        $pool = array_values(array_filter($pool, fn ($id) => ! isset($inRanked[$id])));

        if ($ratio <= 0 || $pool === []) {
            return $ranked;
        }
        if ($ratio >= 1) {
            return array_values(array_unique(array_merge($pool, $ranked)));
        }

        $interval = max(2, (int) round(1 / $ratio));
        $out = [];
        $r = 0;
        $p = 0;
        $total = count($ranked) + count($pool);

        for ($slot = 1; $slot <= $total; $slot++) {
            $wantExplore = $slot % $interval === 0;
            if (($wantExplore && $p < count($pool)) || $r >= count($ranked)) {
                if ($p >= count($pool)) {
                    break;
                }
                $out[] = $pool[$p++];
            } else {
                $out[] = $ranked[$r++];
            }
        }

        return $out;
    }
}
