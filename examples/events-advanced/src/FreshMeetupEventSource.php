<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;

class FreshMeetupEventSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        $now = $context->now();
        $rows = MeetupEvent::query()
            ->where('published', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '>=', $now->subDay());
            })
            ->orderBy('starts_at')
            ->limit($limit)
            ->get(['id']);

        $scores = [];
        foreach ($rows as $i => $row) {
            $scores[$row->id] = 1.0 / (1 + $i);
        }

        return $scores;
    }
}
