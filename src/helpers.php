<?php

use Illuminate\Contracts\Auth\Authenticatable;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Support\FeedPage;

if (! function_exists('recommendations')) {
    /**
     * One page of a feed for $user (a model, an id, or null for guests).
     * The page number defaults to the request's `?page=`.
     *
     *     Route::get('/posts/for-you', fn (Request $request) => recommendations('posts', $request->user()));
     *
     * @param  array<string, mixed>  $attributes
     */
    function recommendations(string $feed, Authenticatable|int|string|null $user = null, ?int $page = null, ?int $perPage = null, array $attributes = []): FeedPage
    {
        return app(Recommender::class)->page($feed, $user, $page, $perPage, $attributes);
    }
}
