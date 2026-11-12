<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced;

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Support\Context;

/**
 * Soft boost for items already in the pool whose type is among the user's
 * most-joined types (does not inject new candidates — pair with FavoriteTypeSource).
 */
class PreferredTypeBooster implements Booster
{
    public function boost(Context $context, array $ids): array
    {
        if (! $context->userId || $ids === []) {
            return [];
        }

        $topTypes = DB::table('meetup_event_joins as j')
            ->join('meetup_events as e', 'e.id', '=', 'j.event_id')
            ->where('j.user_id', $context->userId)
            ->groupBy('e.type_id')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(3)
            ->pluck('e.type_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($topTypes === []) {
            return [];
        }

        $types = MeetupEvent::query()
            ->whereIn('id', $ids)
            ->pluck('type_id', 'id');

        $out = [];
        foreach ($ids as $id) {
            $typeId = (int) ($types[$id] ?? 0);
            if (in_array($typeId, $topTypes, true)) {
                // 1.0 for #1 favorite type, slightly less for #2/#3
                $rank = array_search($typeId, $topTypes, true);
                $out[$id] = 1.0 / (1 + (int) $rank);
            }
        }

        return $out;
    }
}
