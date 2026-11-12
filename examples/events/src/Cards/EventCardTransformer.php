<?php

namespace SolutionForest\Recommender\Examples\Events\Cards;

use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Examples\Events\Models\Event;

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
