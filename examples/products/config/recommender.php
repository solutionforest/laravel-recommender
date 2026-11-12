<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\Products\FreshProductSource;
use SolutionForest\Recommender\Examples\Products\ProductCardTransformer;
use SolutionForest\Recommender\Examples\Products\ProductInteractionSource;
use SolutionForest\Recommender\Examples\Products\ProductSellerGroups;
use SolutionForest\Recommender\Examples\Products\VisibleProductFilter;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;

/*
 * Catalog feed — three event types (view / like / purchase).
 * Copy the `products` feed into your app config/recommender.php.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'products' => [
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
            'exploration_sources' => [TrendingSource::class],
            'exploration_ratio' => 0.05,
            'diversity' => ['seller' => 1],
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshProductSource::class => 0.15,
            ],

            // Any names your InteractionSource yields — not only like/view.
            'events' => [
                'view' => 1.0,
                'like' => 4.0,
                'purchase' => 10.0,
            ],
            'positive_events' => ['like', 'purchase'],
            'exposure_events' => ['view'],

            'interactions' => ProductInteractionSource::class,
            'filter' => VisibleProductFilter::class,
            'cards' => ProductCardTransformer::class,
            'groups' => ProductSellerGroups::class,
        ],
    ],
];
