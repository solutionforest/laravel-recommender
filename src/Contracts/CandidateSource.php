<?php

namespace SolutionForest\Recommender\Contracts;

use SolutionForest\Recommender\Support\Context;

/**
 * Produces candidate items for a user's feed.
 *
 * Implementations must be cheap: one or two indexed queries with a LIMIT.
 * Scores only need to be comparable within the same source; the blender
 * normalises every source to 0..1 before weighting.
 *
 * `$context` is the ranking bag (viewer id, feed config, clock) — see
 * {@see Context}. Guests have `$context->userId === null`.
 */
interface CandidateSource
{
    /**
     * @return array<int|string, float> item id => raw score (higher is better)
     */
    public function candidates(Context $context, int $limit): array;
}
