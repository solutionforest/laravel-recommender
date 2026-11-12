<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Sources;

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;

/** Prefer events in the district where the user lives. */
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

        $events = Event::query()
            ->recommendable($context)
            ->where('district_id', $districtId)
            ->orderBy('starts_at')
            ->limit($limit)
            ->get(['id']);

        return Math::scoreInOrder($events);
    }
}
