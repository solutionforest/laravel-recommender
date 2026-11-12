<?php

namespace SolutionForest\Recommender\Contracts;

/**
 * Turns item ids into the array returned by the API.
 *
 * Results are cached per item id and shared by every user, so cards must not
 * contain viewer-specific data. Return only items that are still visible;
 * ids that are missing from the result are dropped from the page.
 */
interface CardTransformer
{
    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, array<string, mixed>> item id => card
     */
    public function cards(array $ids): array;
}
