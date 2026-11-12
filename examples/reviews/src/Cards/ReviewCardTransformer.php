<?php

namespace SolutionForest\Recommender\Examples\Reviews\Cards;

use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Examples\Reviews\Models\Review;

class ReviewCardTransformer implements CardTransformer
{
    public function cards(array $ids): array
    {
        return Review::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->get()
            ->mapWithKeys(fn (Review $review) => [$review->id => [
                'id' => $review->id,
                'title' => $review->title,
                'shop_id' => $review->shop_id,
                'writer_id' => $review->user_id,
                'cover_url' => $review->cover_url,
                'published_at' => $review->created_at?->toIso8601String(),
            ]])
            ->all();
    }
}
