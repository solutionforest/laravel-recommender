<?php

use SolutionForest\Recommender\Examples\Events\Sources\FreshEventSource;
use SolutionForest\Recommender\Sources\TrendingSource;
use SolutionForest\Recommender\Examples\Events\Models\Event;

/*
 * Events listing — view / like / join → personalised upcoming events.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'events' => [
            // What to recommend, and where interactions live — no classes needed.
            'preset' => 'events',   // weights, join > like > view, quick decay
            'model' => Event::class,
            'interactions' => [
                'event_views' => 'view',
                'event_likes' => 'like',
                'event_joins' => 'join',
            ],

            'page_size' => 10,
            'feed_size' => 200,
            'trending_size' => 200,
            'seen_max' => 500,

            'exploration_sources' => [FreshEventSource::class],
            'diversity' => ['host_id' => 1],
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshEventSource::class => 0.25,
            ],
        ],
    ],
];
