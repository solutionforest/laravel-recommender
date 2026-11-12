<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced;

use SolutionForest\Recommender\Contracts\DiversityGroups;

class MeetupEventGroups implements DiversityGroups
{
    public function groups(array $ids): array
    {
        return MeetupEvent::query()
            ->whereIn('id', $ids)
            ->get(['id', 'host_id', 'district_id', 'type_id'])
            ->mapWithKeys(fn (MeetupEvent $event) => [$event->id => [
                'host' => (string) $event->host_id,
                'district' => (string) $event->district_id,
                'type' => (string) $event->type_id,
            ]])
            ->all();
    }
}
