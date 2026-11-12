<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Http;

use Illuminate\Http\Request;
use SolutionForest\Recommender\Support\FeedPage;

/**
 * GET /events/recommended?page=N — returning the page renders Laravel's paginator JSON
 * (plus `origin` / `has_more`). `?page=` is read automatically.
 */
class RecommendedEventsController
{
    public function __invoke(Request $request): FeedPage
    {
        return recommendations('events', $request->user());
    }
}
