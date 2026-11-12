<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Filters;

use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;

class VisibleEventFilter implements ItemFilter
{
    public function allowed(Context $context, array $ids): array
    {
        return Ids::normalizeAll(
            Event::query()
                ->whereIn('id', $ids)
                ->recommendable($context->now(), $context->userId)
                ->pluck('id')
                ->all()
        );
    }
}
