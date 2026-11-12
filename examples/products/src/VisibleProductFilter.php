<?php

namespace SolutionForest\Recommender\Examples\Products;

use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;

class VisibleProductFilter implements ItemFilter
{
    public function allowed(Context $context, array $ids): array
    {
        return Ids::normalizeAll(Product::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->when($context->userId, fn ($q) => $q->where('seller_id', '<>', $context->userId))
            ->pluck('id')
            ->all());
    }
}
