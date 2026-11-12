<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\Boosters\CommentHeatBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\Cards\EventCardTransformer;
use SolutionForest\Recommender\Examples\EventsAdvanced\Sources\FavoriteHostSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Sources\FavoriteTypeSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Sources\FreshEventSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Boosters\PreferredHostBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\Boosters\PreferredTypeBooster;
use SolutionForest\Recommender\Examples\EventsAdvanced\Sources\SameDistrictSource;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;

/*
 * Advanced events — full copy of events, plus district / host / type / comments.
 * For the simple view/like/join-only version, see examples/events.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'events' => [
            // What to recommend, and where interactions live — no classes needed.
            'preset' => 'events',   // decay + defaults; own sources, boosters and a comment event below
            'model' => Event::class,
            'interactions' => [
                'event_views' => 'view',
                'event_likes' => 'like',
                'event_comments' => 'comment',
                'event_joins' => 'join',
            ],

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
            'diversity' => [               // model attributes
                'host_id' => 1,
                'district_id' => 3,
                'type_id' => 2,
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
            // A class still works when a card needs more than one row: this one
            // adds comment counts with a single grouped query (no N+1).
            'cards' => EventCardTransformer::class,
        ],
    ],
];
