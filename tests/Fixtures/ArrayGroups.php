<?php

namespace SolutionForest\Recommender\Tests\Fixtures;

use SolutionForest\Recommender\Contracts\DiversityGroups;

class ArrayGroups implements DiversityGroups
{
    /** shop = id div 10 */
    public function groups(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['shop' => intdiv((int) $id, 10)];
        }

        return $out;
    }
}
