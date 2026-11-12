<?php

namespace SolutionForest\Recommender\Examples\Events\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\Events\Models\Event;

/** Soonest upcoming published events — guest / explore fallback. */
class FreshEventSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        $now = $context->now();
        $rows = Event::query()
            ->where('published', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '>=', $now->subDay());
            })
            ->orderBy('starts_at')
            ->limit($limit)
            ->get(['id', 'starts_at']);

        $scores = [];
        foreach ($rows as $i => $row) {
            $scores[$row->id] = Math::fromPosition($i);
        }

        return $scores;
    }
}
