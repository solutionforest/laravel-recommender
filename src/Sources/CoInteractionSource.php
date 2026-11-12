<?php

namespace SolutionForest\Recommender\Sources;

use Illuminate\Contracts\Container\Container;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Storage\NeighbourStore;
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
        private readonly Container $container,
    ) {
    }

    public function candidates(Context $context, int $limit): array
    {
        if ($context->isGuest()) {
            return [];
        }

        $class = $context->feed->get('interactions');
        if (! $class) {
            return [];
        }

        /** @var InteractionSource $interactions */
        $interactions = $this->container->make($class);
        $recent = $interactions->recentItems($context->userId, $context->feed->int('recent_items', 20));
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
