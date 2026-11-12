<?php

namespace SolutionForest\Recommender\Contracts;

use SolutionForest\Recommender\Support\Context;

/**
 * Business rules: removes items that must never be shown
 * (hidden, unapproved, the user's own, blocked authors, ...).
 *
 * Called once per feed build with every candidate id, so implement it as a
 * single primary-key query. $context->userId is null for the shared
 * trending list.
 */
interface ItemFilter
{
    /**
     * @param  list<int|string>  $ids
     * @return list<int|string> the subset of $ids that may be shown
     */
    public function allowed(Context $context, array $ids): array;
}
