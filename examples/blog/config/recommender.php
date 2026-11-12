<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\Blog\FreshPostSource;
use SolutionForest\Recommender\Examples\Blog\PostAuthorGroups;
use SolutionForest\Recommender\Examples\Blog\PostCardTransformer;
use SolutionForest\Recommender\Examples\Blog\PostInteractionSource;
use SolutionForest\Recommender\Examples\Blog\SponsoredShopBooster;
use SolutionForest\Recommender\Examples\Blog\SponsoredShopSource;
use SolutionForest\Recommender\Examples\Blog\VisiblePostFilter;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Sources\TrendingSource;

/*
 * Step 4 — wire the feed. Copy to config/recommender.php in your app.
 *
 * Lab defaults: personalisation first (not latest-first).
 * Sponsors: fill sponsored_author_ids (blog) or sponsored_shop_ids (reviews apps).
 * Infinite scroll: sizes below + InfiniteScrollFeed catalog tail when hasMore is false.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    // empty → app CACHE_STORE. Prefer redis in prod; database OK with cache_locks (L11–13).
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    'feeds' => [
        'posts' => [
            'page_size' => 10,
            'feed_size' => 200,
            'trending_size' => 200,
            'seen_max' => 500,

            // Paid / campaign — empty = no effect. Example uses author_id as "shop".
            'sponsored_author_ids' => [
                // 5, 8,
            ],

            'sources' => [
                CoInteractionSource::class => 0.55,
                TrendingSource::class => 0.25,
                SponsoredShopSource::class => 0.20,   // inject sponsored posts
            ],
            'boosters' => [
                QualityBooster::class => 0.15,
                SponsoredShopBooster::class => 0.25,  // rank them higher
            ],
            'exploration_sources' => [TrendingSource::class],
            'exploration_ratio' => 0.05,
            'diversity' => ['author' => 1],            // sponsor cannot fill whole page
            'trending_sources' => [
                TrendingSource::class => 1.0,
                FreshPostSource::class => 0.15,        // guests / empty scores only
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
