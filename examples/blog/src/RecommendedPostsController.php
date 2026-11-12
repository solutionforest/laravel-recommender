<?php

namespace SolutionForest\Recommender\Examples\Blog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SolutionForest\Recommender\Facades\Recommender;

/**
 * Step 5 — serve it. GET /posts/recommended?page=N
 */
class RecommendedPostsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $user = $request->user();

        $result = Recommender::page('posts', $user?->getAuthIdentifier(), $page, attributes: ['user' => $user]);

        return response()->json($result->toPaginatorArray([
            'path' => $request->url(),
            'query' => $request->query(),
        ]));
    }
}
