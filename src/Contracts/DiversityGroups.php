<?php

namespace SolutionForest\Recommender\Contracts;

/**
 * Group keys used to limit how many items of the same group appear on one
 * page (configured with the feed's 'diversity' option).
 */
interface DiversityGroups
{
    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, array<string, int|string|null>> item id => [group name => group key]
     */
    public function groups(array $ids): array;
}
