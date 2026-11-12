<?php

namespace SolutionForest\Recommender\Examples\Events;

use SolutionForest\Recommender\Contracts\CardTransformer;

class EventCardTransformer implements CardTransformer
{
    public function cards(array $ids): array
    {
        return Event::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->get()
            ->mapWithKeys(fn (Event $event) => [$event->id => [
                'id' => $event->id,
                'title' => $event->title,
                'host_id' => $event->host_id,
                'starts_at' => $event->starts_at?->toIso8601String(),
            ]])
            ->all();
    }
}
