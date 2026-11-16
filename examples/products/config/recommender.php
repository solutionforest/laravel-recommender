<?php

use SolutionForest\Recommender\Examples\Products\Sources\FreshProductSource;
use SolutionForest\Recommender\Sources\AttributeMatchSource;
use SolutionForest\Recommender\Sources\CoInteractionSource;
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

            // New products have no likes or purchases yet: find them by their own
            // attributes. similar() falls back to this for products without
            // neighbours; AttributeMatchSource adds them to personal feeds.
            'match' => [
                'category_id' => 2.0,                             // same category
                'price' => ['weight' => 1.0, 'type' => 'range'],  // similar price
            ],
            'sources' => [                                       // the catalog preset's, plus attribute match
                CoInteractionSource::class => 0.50,
                AttributeMatchSource::class => 0.15,
                TrendingSource::class => 0.35,
            ],

            'diversity' => ['seller_id' => 1],
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshProductSource::class => 0.15,
            ],
        ],
    ],
];
