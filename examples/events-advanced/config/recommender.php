<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\FavoriteTypeSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\FreshMeetupEventSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\MeetupEventCardTransformer;
use SolutionForest\Recommender\Examples\EventsAdvanced\MeetupEventGroups;
use SolutionForest\Recommender\Examples\EventsAdvanced\MeetupEventInteractionSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\PreferredTypeBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\SameDistrictSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\VisibleMeetupEventFilter;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;

/*
 * Advanced meetup feed — district + favorite event-type rules on top of CF/trending.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'meetup_events' => [
            'page_size' => 10,
            'feed_size' => 200,
            'trending_size' => 200,
            'seen_max' => 500,
            'source_limit' => 100,

            'sources' => [
                SameDistrictSource::class => 0.30,
                FavoriteTypeSource::class => 0.25,
                CoInteractionSource::class => 0.25,
                TrendingSource::class => 0.20,
            ],
            'boosters' => [
                PreferredTypeBooster::class => 0.20,
                QualityBooster::class => 0.15,
            ],
            'exploration_sources' => [FreshMeetupEventSource::class],
            'exploration_ratio' => 0.08,
            'diversity' => [
                'host' => 1,
                'district' => 3,
                'type' => 2,
            ],
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshMeetupEventSource::class => 0.25,
            ],

            'events' => [
                'view' => 1.0,
                'like' => 4.0,
                'join' => 10.0,
            ],
            'positive_events' => ['like', 'join'],
            'exposure_events' => ['view'],

            'interactions' => MeetupEventInteractionSource::class,
            'filter' => VisibleMeetupEventFilter::class,
            'cards' => MeetupEventCardTransformer::class,
            'groups' => MeetupEventGroups::class,
        ],
    ],
];
