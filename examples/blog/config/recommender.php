<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\Blog\FreshPostSource;
use SolutionForest\Recommender\Examples\Blog\PostAuthorGroups;
use SolutionForest\Recommender\Examples\Blog\PostCardTransformer;
use SolutionForest\Recommender\Examples\Blog\PostInteractionSource;
use SolutionForest\Recommender\Examples\Blog\VisiblePostFilter;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;

/*
 * Step 4 — wire the feed. Copy to config/recommender.php in your app.
 * Every key from the package's 'defaults' can be overridden per feed.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE'),   // redis in production

    'feeds' => [
        'posts' => [
            'page_size' => 10,

            'sources' => [
                CoInteractionSource::class => 0.6,     // people who liked what you liked
                TrendingSource::class => 0.2,          // time-decayed likes + views
            ],
            'boosters' => [
                QualityBooster::class => 0.2,          // like-rate (Wilson lower bound)
            ],
            'exploration_sources' => [FreshPostSource::class],
            'exploration_ratio' => 0.15,
            'diversity' => ['author' => 1],            // max 1 post per author per page
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshPostSource::class => 0.3,         // guests still see something before scores exist
            ],

            'events' => ['view' => 1.0, 'like' => 4.0],
            'positive_events' => ['like'],
            'exposure_events' => ['view'],

            'interactions' => PostInteractionSource::class,
            'filter' => VisiblePostFilter::class,
            'cards' => PostCardTransformer::class,
            'groups' => PostAuthorGroups::class,
        ],
    ],
];
