<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced;

use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;

class VisibleMeetupEventFilter implements ItemFilter
{
    public function allowed(Context $context, array $ids): array
    {
        return Ids::normalizeAll(MeetupEvent::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->where(function ($q) use ($context) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '>=', $context->now()->subDay());
            })
            ->when($context->userId, fn ($q) => $q->where('host_id', '<>', $context->userId))
            ->pluck('id')
            ->all());
    }
}
