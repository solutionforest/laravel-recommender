<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Examples\Blog\FreshnessBooster;
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
 *
 * Lab recipes (see main README “Fine-tuning weights”):
 * - Inspiration / segments: personal sources high, Fresh* + FreshnessBooster low.
 * - Latest-first: raise Fresh* / exploration — cohorts look more alike.
 * - Infinite scroll: raise feed_size / trending_size / seen_max, then catalog tail
 *   via examples/blog/src/InfiniteScrollFeed.php when hasMore is false.
 */
return [
    'enabled' => env('RECOMMENDER_ENABLED', true),
    'cache_store' => env('RECOMMENDER_CACHE_STORE'),   // redis in production

    'feeds' => [
        'posts' => [
            'page_size' => 10,
            // Longer ranked pool before an app-level chronological tail.
            'feed_size' => 200,
            'trending_size' => 200,
            'seen_max' => 500,

            'sources' => [
                CoInteractionSource::class => 0.55,    // people who liked what you liked
                TrendingSource::class => 0.25,         // time-decayed likes + views
                FreshPostSource::class => 0.20,        // spice — keep modest for “for you”
            ],
            'boosters' => [
                QualityBooster::class => 0.20,         // Wilson lower bound
                FreshnessBooster::class => 0.05,       // lab: small; large ⇒ same feed for everyone
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
