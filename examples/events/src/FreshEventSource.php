<?php

namespace SolutionForest\Recommender\Examples\Events;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;

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
            $scores[$row->id] = 1.0 / (1 + $i);
        }

        return $scores;
    }
}
