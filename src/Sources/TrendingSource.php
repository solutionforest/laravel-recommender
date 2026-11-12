<?php

namespace SolutionForest\Recommender\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Support\Context;

/**
 * Items with the highest time-decayed popularity (rec_item_scores.hot).
 * One indexed query: ORDER BY hot DESC LIMIT n.
 */
final class TrendingSource implements CandidateSource
{
    public function __construct(private readonly ScoreStore $scores)
    {
    }

    public function candidates(Context $context, int $limit): array
    {
        return $this->scores->hottest($context->feed->name, $limit);
    }
}
