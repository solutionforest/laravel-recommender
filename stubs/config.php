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

    // false = off: everyone gets the shared trending list, nothing is built or
    // written, jobs pause. One feed only: 'enabled' => false on that feed.
    'enabled' => env('RECOMMENDER_ENABLED', true),

    // Leave empty: Redis is used automatically when your app has a working Redis
    // store (best: fast, atomic locks, free expiry), otherwise your CACHE_STORE.
    // Set RECOMMENDER_CACHE_STORE=redis|database|… only to force one.
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    // How many served ids to remember per user (no repeats) and for how long.
    // Env: RECOMMENDER_SEEN_MAX (default 200), RECOMMENDER_SEEN_TTL (seconds, default 3 days).

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
