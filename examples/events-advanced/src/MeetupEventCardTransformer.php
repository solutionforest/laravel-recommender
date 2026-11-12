<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced;

use SolutionForest\Recommender\Contracts\CardTransformer;

class MeetupEventCardTransformer implements CardTransformer
{
    public function cards(array $ids): array
    {
        return MeetupEvent::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->get()
            ->mapWithKeys(fn (MeetupEvent $event) => [$event->id => [
                'id' => $event->id,
                'title' => $event->title,
                'host_id' => $event->host_id,
                'district_id' => $event->district_id,
                'type_id' => $event->type_id,
                'starts_at' => $event->starts_at?->toIso8601String(),
            ]])
            ->all();
    }
}
