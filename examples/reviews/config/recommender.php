<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\Reviews\FreshReviewSource;
use SolutionForest\Recommender\Examples\Reviews\PetTypeMatchSource;
use SolutionForest\Recommender\Examples\Reviews\ReviewCardTransformer;
use SolutionForest\Recommender\Examples\Reviews\ReviewInteractionSource;
use SolutionForest\Recommender\Examples\Reviews\ReviewShopGroups;
use SolutionForest\Recommender\Examples\Reviews\VisibleReviewFilter;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;

/*
 * Inspiration-style reviews feed — personalisation + pet-type rule source.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'reviews' => [
            'page_size' => 12,
            'feed_size' => 200,
            'trending_size' => 200,
            'seen_max' => 500,
            'source_limit' => 100,

            'sources' => [
                PetTypeMatchSource::class => 0.40,
                CoInteractionSource::class => 0.35,
                TrendingSource::class => 0.25,
            ],
            'boosters' => [
                QualityBooster::class => 0.15,
            ],
            'exploration_sources' => [TrendingSource::class],
            'exploration_ratio' => 0.05,
            'diversity' => ['shop' => 2, 'writer' => 1],
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshReviewSource::class => 0.15,
            ],

            'events' => ['view' => 1.0, 'like' => 4.0],
            'positive_events' => ['like'],
            'exposure_events' => ['view'],

            'interactions' => ReviewInteractionSource::class,
            'filter' => VisibleReviewFilter::class,
            'cards' => ReviewCardTransformer::class,
            'groups' => ReviewShopGroups::class,
        ],
    ],
];
