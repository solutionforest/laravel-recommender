<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\Events\EventCardTransformer;
use SolutionForest\Recommender\Examples\Events\EventHostGroups;
use SolutionForest\Recommender\Examples\Events\EventInteractionSource;
use SolutionForest\Recommender\Examples\Events\FreshEventSource;
use SolutionForest\Recommender\Examples\Events\VisibleEventFilter;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;

/*
 * Events listing — view / like / join → personalised upcoming events.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'events' => [
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
            'diversity' => ['host' => 1],
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

            'interactions' => EventInteractionSource::class,
            'filter' => VisibleEventFilter::class,
            'cards' => EventCardTransformer::class,
            'groups' => EventHostGroups::class,
        ],
    ],
];
