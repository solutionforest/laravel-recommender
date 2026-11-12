<?php

namespace SolutionForest\Recommender\Examples\Events;

use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;

class VisibleEventFilter implements ItemFilter
{
    public function allowed(Context $context, array $ids): array
    {
        return Ids::normalizeAll(Event::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->where(function ($q) use ($context) {
                // Upcoming or recently started; null starts_at still allowed (lab / evergreen).
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '>=', $context->now()->subDay());
            })
            ->when($context->userId, fn ($q) => $q->where('host_id', '<>', $context->userId))
            ->pluck('id')
            ->all());
    }
}
