<?php

use SolutionForest\Recommender\Examples\Products\Sources\FreshProductSource;
use SolutionForest\Recommender\Sources\TrendingSource;
use SolutionForest\Recommender\Examples\Products\Models\Product;

/*
 * Catalog feed — three event types (view / like / purchase).
 * Copy the `products` feed into your app config/recommender.php.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'products' => [
            // What to recommend, and where interactions live — no classes needed.
            'preset' => 'catalog',   // weights, purchase > like > view, decay, exploration
            'model' => Product::class,
            'interactions' => [
                'product_views' => 'view',
                'product_likes' => 'like',
                'product_purchases' => 'purchase',
            ],

            'page_size' => 10,
            'feed_size' => 200,
            'trending_size' => 200,
            'seen_max' => 500,

            'diversity' => ['seller_id' => 1],
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshProductSource::class => 0.15,
            ],
        ],
    ],
];
