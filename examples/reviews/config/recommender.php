<?php

use SolutionForest\Recommender\Examples\Reviews\Sources\FreshReviewSource;
use SolutionForest\Recommender\Examples\Reviews\Sources\PetTypeMatchSource;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;
use SolutionForest\Recommender\Examples\Reviews\Models\Review;

/*
 * Reviews feed — personalisation + a same-pet-type rule source (PetTypeMatchSource).
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'reviews' => [
            // What to recommend, and where interactions live — no classes needed.
            'preset' => 'social',   // boosters, events, decay; own sources below
            'model' => Review::class,
            'interactions' => [
                'review_likes' => 'like',
                'review_views' => 'view',
            ],

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
            'exploration_ratio' => 0.05,
            'diversity' => ['shop_id' => 2, 'user_id' => 1], // per shop / per writer
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshReviewSource::class => 0.15,
            ],
        ],
    ],
];
