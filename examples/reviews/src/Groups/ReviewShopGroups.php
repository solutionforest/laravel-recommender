<?php

namespace SolutionForest\Recommender\Examples\Reviews\Groups;

use SolutionForest\Recommender\Contracts\DiversityGroups;
use SolutionForest\Recommender\Examples\Reviews\Models\Review;

class ReviewShopGroups implements DiversityGroups
{
    public function groups(array $ids): array
    {
        return Review::query()
            ->whereIn('id', $ids)
            ->get(['id', 'shop_id', 'user_id'])
            ->mapWithKeys(fn (Review $review) => [$review->id => [
                'shop' => (string) $review->shop_id,
                'writer' => (string) $review->user_id,
            ]])
            ->all();
    }
}
