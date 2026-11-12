<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;

/** Soonest upcoming published events — guest / explore fallback. */
class FreshEventSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        $events = Event::query()
            ->recommendable($context)
            ->orderBy('starts_at')
            ->limit($limit)
            ->get(['id']);

        return Math::scoreInOrder($events);
    }
}
