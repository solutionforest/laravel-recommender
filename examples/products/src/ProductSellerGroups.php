<?php

namespace SolutionForest\Recommender\Examples\Products;

use SolutionForest\Recommender\Contracts\DiversityGroups;

class ProductSellerGroups implements DiversityGroups
{
    public function groups(array $ids): array
    {
        return Product::query()
            ->whereIn('id', $ids)
            ->pluck('seller_id', 'id')
            ->map(fn ($sellerId) => ['seller' => (string) $sellerId])
            ->all();
    }
}
