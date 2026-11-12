<?php

namespace SolutionForest\Recommender\Examples\Events\Groups;

use SolutionForest\Recommender\Contracts\DiversityGroups;
use SolutionForest\Recommender\Examples\Events\Models\Event;

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
