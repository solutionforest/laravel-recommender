<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced;

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;

/**
 * Prefer events in the district where the user lives.
 */
class SameDistrictSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        if (! $context->userId) {
            return [];
        }

        $districtId = DB::table('user_districts')
            ->where('user_id', $context->userId)
            ->value('district_id');

        if ($districtId === null) {
            return [];
        }

        $now = $context->now();
        $rows = MeetupEvent::query()
            ->where('district_id', $districtId)
            ->where('published', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '>=', $now->subDay());
            })
            ->when($context->userId, fn ($q) => $q->where('host_id', '<>', $context->userId))
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
