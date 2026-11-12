<?php

namespace SolutionForest\Recommender\Contracts;

use SolutionForest\Recommender\Support\Context;

/**
 * Adds a score component to items that are already candidates
 * (e.g. quality, freshness). Never introduces new items.
 */
interface Booster
{
    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, float> item id => value in 0..1
     */
    public function boost(Context $context, array $ids): array;
}
