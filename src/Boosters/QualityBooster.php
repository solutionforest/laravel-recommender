<?php

namespace SolutionForest\Recommender\Boosters;

use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Support\Context;

/**
 * Wilson lower bound of positive / exposure (e.g. likes / views).
 * Items with few views are not over-rewarded for one lucky like.
 * Values are scaled so the best candidate in the batch gets 1.0.
 */
final class QualityBooster implements Booster
{
    public function __construct(private readonly ScoreStore $scores)
    {
    }

    public function boost(Context $context, array $ids): array
    {
        $out = [];
        // With impressions on, "how often was it liked when shown" is the better denominator.
        $basis = $context->feed->get('impressions') ? 'impressions' : 'exposure';
        foreach ($this->scores->counts($context->feed->name, $ids) as $id => $row) {
            $out[$id] = Math::wilson($row['positive'], $row[$basis]);
        }

        // Relative to the best candidate: absolute like-rates are small, the
        // ordering between candidates is what matters.
        return Math::normalizeMax($out);
    }
}
