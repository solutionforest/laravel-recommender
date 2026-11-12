<?php

namespace SolutionForest\Recommender\Examples\Reviews\Http;

use Illuminate\Http\Request;
use SolutionForest\Recommender\Examples\Reviews\Models\Review;
use SolutionForest\Recommender\Support\FeedPage;

/**
 * GET /reviews/recommended?page=N — returning the page renders Laravel's paginator JSON
 * (plus `origin` / `has_more`). `?page=` is read automatically.
 */
class RecommendedReviewsController
{
    public function __invoke(Request $request): FeedPage
    {
        return Review::recommendedFor($request->user());
    }
}
