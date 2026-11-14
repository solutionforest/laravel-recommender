<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Cards;

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;
use SolutionForest\Recommender\Support\Value;

class EventCardTransformer implements CardTransformer
{
    public function cards(array $ids): array
    {
        $commentCounts = DB::table('event_comments')
            ->whereIn('event_id', $ids)
            ->groupBy('event_id')
            ->pluck(DB::raw('count(*) as c'), 'event_id');

        return Event::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->get()
            ->mapWithKeys(fn (Event $event) => [$event->id => [
                'id' => $event->id,
                'title' => $event->title,
                'host_id' => $event->host_id,
                'district_id' => $event->district_id,
                'type_id' => $event->type_id,
                'comment_count' => Value::int($commentCounts[$event->id] ?? 0),
                'starts_at' => $event->starts_at?->toIso8601String(),
            ]])
            ->all();
    }
}
