<?php

namespace SolutionForest\Recommender\Examples\Events;

use SolutionForest\Recommender\Contracts\DiversityGroups;

class EventHostGroups implements DiversityGroups
{
    public function groups(array $ids): array
    {
        return Event::query()
            ->whereIn('id', $ids)
            ->pluck('host_id', 'id')
            ->map(fn ($hostId) => ['host' => (string) $hostId])
            ->all();
    }
}
