<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Boosters;

use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;
use SolutionForest\Recommender\Examples\EventsAdvanced\Support\JoinPreferences;

/** Soft boost when a candidate’s type is one the user joins most. */
class PreferredTypeBooster implements Booster
{
    public function boost(Context $context, array $ids): array
    {
        if (! $context->userId || $ids === []) {
            return [];
        }

        $favoriteTypes = JoinPreferences::types($context->userId)->keys()->all();

        if ($favoriteTypes === []) {
            return [];
        }

        $typeById = Event::query()->whereIn('id', $ids)->pluck('type_id', 'id')->all();

        return Math::preferRanked($ids, $favoriteTypes, $typeById);
    }
}
