<?php

namespace SolutionForest\Recommender\Examples\Blog\Http;

use Illuminate\Http\Request;
use SolutionForest\Recommender\Support\FeedPage;

/**
 * GET /posts/recommended?page=N — returning the page renders Laravel's paginator JSON
 * (plus `origin` / `has_more`). `?page=` is read automatically.
 */
class RecommendedPostsController
{
    public function __invoke(Request $request): FeedPage
    {
        return recommendations('posts', $request->user());
    }
}
