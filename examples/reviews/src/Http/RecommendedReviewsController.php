<?php

namespace SolutionForest\Recommender\Examples\Reviews\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SolutionForest\Recommender\Facades\Recommender;

/** GET /reviews/recommended?page=N */
class RecommendedReviewsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $user = $request->user();

        $result = Recommender::page('reviews', $user?->getAuthIdentifier(), $page, attributes: ['user' => $user]);

        return response()->json($result->toPaginatorArray([
            'path' => $request->url(),
            'query' => $request->query(),
        ]));
    }
}
