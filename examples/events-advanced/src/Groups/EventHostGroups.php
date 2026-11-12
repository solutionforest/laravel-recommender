<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Groups;

use SolutionForest\Recommender\Contracts\DiversityGroups;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;

class EventHostGroups implements DiversityGroups
{
    public function groups(array $ids): array
    {
        return Event::query()
            ->whereIn('id', $ids)
            ->get(['id', 'host_id', 'district_id', 'type_id'])
            ->mapWithKeys(fn (Event $event) => [$event->id => [
                'host' => (string) $event->host_id,
                'district' => (string) $event->district_id,
                'type' => (string) $event->type_id,
            ]])
            ->all();
    }
}
