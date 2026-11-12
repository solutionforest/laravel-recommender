<?php

namespace SolutionForest\Recommender\Examples\Reviews\Filters;

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Examples\Reviews\Models\Review;

class VisibleReviewFilter implements ItemFilter
{
    public function allowed(Context $context, array $ids): array
    {
        return Ids::normalizeAll(Review::query()
            ->whereIn('reviews.id', $ids)
            ->where('reviews.published', true)
            ->whereNotNull('reviews.cover_url')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('shops')
                    ->whereColumn('shops.id', 'reviews.shop_id')
                    ->where('shops.is_opening', true);
            })
            ->when($context->userId, fn ($q) => $q->where('reviews.user_id', '<>', $context->userId))
            ->pluck('reviews.id')
            ->all());
    }
}
