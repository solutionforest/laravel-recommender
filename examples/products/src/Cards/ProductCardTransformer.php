<?php

namespace SolutionForest\Recommender\Examples\Products\Cards;

use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Examples\Products\Models\Product;

class ProductCardTransformer implements CardTransformer
{
    public function cards(array $ids): array
    {
        return Product::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->get()
            ->mapWithKeys(fn (Product $product) => [$product->id => [
                'id' => $product->id,
                'title' => $product->title,
                'seller_id' => $product->seller_id,
                'published_at' => $product->created_at?->toIso8601String(),
            ]])
            ->all();
    }
}
