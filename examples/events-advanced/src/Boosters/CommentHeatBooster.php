<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Boosters;

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Context;

/** Soft boost for events that already have discussion. */
class CommentHeatBooster implements Booster
{
    public function boost(Context $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = DB::table('event_comments')
            ->whereIn('event_id', $ids)
            ->groupBy('event_id')
            ->pluck(DB::raw('count(*) as total'), 'event_id');

        $heat = collect($ids)
            ->filter(fn ($id) => (int) ($counts[$id] ?? 0) > 0)
            ->mapWithKeys(fn ($id) => [$id => log1p((int) $counts[$id])])
            ->all();

        return Math::normalizeMax($heat);
    }
}
