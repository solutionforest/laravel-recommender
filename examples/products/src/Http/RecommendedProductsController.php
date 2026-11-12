<?php

namespace SolutionForest\Recommender\Examples\Products\Http;

use Illuminate\Http\Request;
use SolutionForest\Recommender\Examples\Products\Models\Product;
use SolutionForest\Recommender\Support\FeedPage;

/**
 * GET /products/recommended?page=N — returning the page renders Laravel's paginator JSON
 * (plus `origin` / `has_more`). `?page=` is read automatically.
 */
class RecommendedProductsController
{
    public function __invoke(Request $request): FeedPage
    {
        return Product::recommendedFor($request->user());
    }
}
