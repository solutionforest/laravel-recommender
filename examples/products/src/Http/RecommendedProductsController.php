<?php

namespace SolutionForest\Recommender\Examples\Products\Http;

use Illuminate\Http\Request;
use SolutionForest\Recommender\Support\FeedPage;

/**
 * GET /products/recommended?page=N — returning the page renders Laravel's paginator JSON
 * (plus `origin` / `has_more`). `?page=` is read automatically.
 */
class RecommendedProductsController
{
    public function __invoke(Request $request): FeedPage
    {
        return recommendations('products', $request->user());
    }
}
