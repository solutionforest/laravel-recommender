<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Boosters;

use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;
use SolutionForest\Recommender\Examples\EventsAdvanced\Support\JoinPreferences;

/** Soft boost when a candidate’s host is one the user joins most. */
class PreferredHostBooster implements Booster
{
    public function boost(Context $context, array $ids): array
    {
        if (! $context->userId || $ids === []) {
            return [];
        }

        $favoriteHosts = array_values(JoinPreferences::hosts($context->userId)->keys()->all());

        if ($favoriteHosts === []) {
            return [];
        }

        $hostById = Event::query()->whereIn('id', $ids)->pluck('host_id', 'id')->all();

        return Math::preferRanked($ids, $favoriteHosts, $hostById);
    }
}
