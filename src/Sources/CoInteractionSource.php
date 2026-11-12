<?php

namespace SolutionForest\Recommender\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Storage\NeighbourStore;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\Context;

/**
 * "People who interacted with what you interacted with also liked ..."
 *
 * Takes the user's most recent items, reads their precomputed neighbours
 * (one primary-key query) and sums the similarities, giving more weight to
 * the user's most recent items.
 */
final class CoInteractionSource implements CandidateSource
{
    public function __construct(
        private readonly NeighbourStore $neighbours,
        private readonly Bindings $bindings,
    ) {
    }

    public function candidates(Context $context, int $limit): array
    {
        if ($context->isGuest()) {
            return [];
        }

        $interactions = $this->bindings->interactions($context->feed);
        $userId = $context->userId;
        if ($interactions === null || $userId === null) {
            return [];
        }

        $recent = $interactions->recentItems($userId, $context->feed->int('recent_items', 20));
        if ($recent === []) {
            return [];
        }

        $neighbours = $this->neighbours->neighboursOf($context->feed->name, $recent);
        $exclude = array_flip($recent);
        $scores = [];

        foreach ($recent as $position => $itemId) {
            $recency = 1 / (1 + 0.1 * $position);
            foreach ($neighbours[$itemId] ?? [] as [$candidate, $similarity]) {
                if (isset($exclude[$candidate])) {
                    continue;
                }
                $scores[$candidate] = ($scores[$candidate] ?? 0.0) + $similarity * $recency;
            }
        }

        arsort($scores);

        return array_slice($scores, 0, $limit, true);
    }
}
