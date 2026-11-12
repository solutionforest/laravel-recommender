<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\Events\Sources\FreshEventSource;
use SolutionForest\Recommender\Sources\CoInteractionSource;
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

            'sources' => [
                CoInteractionSource::class => 0.55,
                TrendingSource::class => 0.35,
            ],
            'boosters' => [
                QualityBooster::class => 0.20,
            ],
            'exploration_sources' => [FreshEventSource::class],
            'exploration_ratio' => 0.08,
            'diversity' => ['host_id' => 1],
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshEventSource::class => 0.25,
            ],

            'events' => [
                'view' => 1.0,
                'like' => 4.0,
                'join' => 10.0,
            ],
            'positive_events' => ['like', 'join'],
            'exposure_events' => ['view'],
        ],
    ],
];
