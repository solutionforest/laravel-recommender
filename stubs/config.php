<?php

/*
|--------------------------------------------------------------------------
| Laravel Recommender
|--------------------------------------------------------------------------
| Only what most apps change. Every other option has a sensible default in
| the package — see docs/reference.md#configuration, or publish the full file
| with: php artisan vendor:publish --tag=recommender-config-full
*/
return [

    'enabled' => env('RECOMMENDER_ENABLED', true),

    // null = your CACHE_STORE. Use a store with locks in production (redis / database).
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    // Jobs are scheduled for you; set RECOMMENDER_SCHEDULE=false to do it yourself.
    // 'schedule' => ['scores' => '*/10 * * * *', 'neighbours' => '30 3 * * *', 'prune' => '30 4 * * *'],

    // Dashboard at /recommender (open locally; elsewhere Gate::define('viewRecommender', …)).
    // 'dashboard' => ['path' => 'recommender', 'middleware' => ['web']],

    /*
    | One entry per feed. The smallest useful feed:
    |
    | 'posts' => [
    |     'preset' => 'social',                 // catalog | social | events
    |     'model' => App\Models\Post::class,    // scopeRecommendable() + toRecommendationCard()
    |     'interactions' => ['post_likes' => 'like', 'post_views' => 'view'],
    | ],
    */
    'feeds' => [
    ],
];
