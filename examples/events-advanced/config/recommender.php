<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\Boosters\CommentHeatBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\Cards\EventCardTransformer;
use SolutionForest\Recommender\Examples\EventsAdvanced\Groups\EventHostGroups;
use SolutionForest\Recommender\Examples\EventsAdvanced\Interactions\EventInteractionSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Sources\FavoriteHostSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Sources\FavoriteTypeSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Sources\FreshEventSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Boosters\PreferredHostBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\Boosters\PreferredTypeBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\Sources\SameDistrictSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Filters\VisibleEventFilter;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;

/*
 * Advanced events — full copy of events, plus district / host / type / comments.
 * For the simple view/like/join-only version, see examples/events.
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
            'source_limit' => 100,

            'sources' => [
                SameDistrictSource::class => 0.22,
                FavoriteTypeSource::class => 0.18,
                FavoriteHostSource::class => 0.18,
                CoInteractionSource::class => 0.22,
                TrendingSource::class => 0.20,
            ],
            'boosters' => [
                PreferredTypeBooster::class => 0.12,
                PreferredHostBooster::class => 0.12,
                CommentHeatBooster::class => 0.15,
                QualityBooster::class => 0.10,
            ],
            'exploration_sources' => [FreshEventSource::class],
            'exploration_ratio' => 0.08,
            'diversity' => [
                'host' => 1,
                'district' => 3,
                'type' => 2,
            ],
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshEventSource::class => 0.25,
            ],

            'events' => [
                'view' => 1.0,
                'like' => 4.0,
                'comment' => 6.0,
                'join' => 10.0,
            ],
            'positive_events' => ['like', 'comment', 'join'],
            'exposure_events' => ['view'],

            'interactions' => EventInteractionSource::class,
            'filter' => VisibleEventFilter::class,
            'cards' => EventCardTransformer::class,
            'groups' => EventHostGroups::class,
        ],
    ],
];
