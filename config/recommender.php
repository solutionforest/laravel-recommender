<?php

return [

    /*
    |--------------------------------------------------------------------------
    | On / off
    |--------------------------------------------------------------------------
    | RECOMMENDER_ENABLED=false switches the package off: every page serves the
    | shared trending list (from the last computed scores), nothing personal is
    | built, nothing is written, warm() does nothing and the scheduled jobs
    | pause. Routes keep working. One feed only: 'enabled' => false on the feed.
    */
    'enabled' => env('RECOMMENDER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    | Store for ranked lists, seen-sets, cards, trending, and per-user build locks.
    | null / empty / 'auto' = Redis when the app has a working Redis store,
    | otherwise Laravel's default CACHE_STORE.
    |
    | Any store that implements LockProvider works for build locks, including:
    |   - redis     (recommended in production — fast, atomic)
    |   - database  (fine on Laravel 10–13 if cache + cache_locks tables exist)
    |   - array/file (local / single-process; locks exist but are not multi-server)
    |
    | If the store cannot provide locks, the package falls back to building
    | without a lock (last write wins). Check: php artisan recommender:status
    |
    | Examples:
    |   RECOMMENDER_CACHE_STORE=redis
    |   RECOMMENDER_CACHE_STORE=database
    |   (omit) → Redis if reachable, else CACHE_STORE
    */
    // null / 'auto' = Redis when the app has a working Redis store, else CACHE_STORE.
    'cache_store' => env('RECOMMENDER_CACHE_STORE') ?: null,

    /* Prefix for every cache key the package writes (easy to flush). */
    'prefix' => env('RECOMMENDER_PREFIX', 'rec'),

    /* Queue + connection used by the BuildFeed warm-up job. */
    'queue' => env('RECOMMENDER_QUEUE', 'recommender'),
    'queue_connection' => env('RECOMMENDER_QUEUE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    | One read-only page at /{path}: health, checks, trending, explain.
    | Open in the local environment; elsewhere define the gate, as with Horizon:
    |
    |   Gate::define('viewRecommender', fn ($user) => $user->is_admin);
    */
    'dashboard' => [
        'enabled' => env('RECOMMENDER_DASHBOARD', true),
        'path' => env('RECOMMENDER_DASHBOARD_PATH', 'recommender'),
        'domain' => env('RECOMMENDER_DASHBOARD_DOMAIN'),
        'middleware' => ['web'],
        'refresh' => 10, // seconds between live updates (0 = off)
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    | The package schedules its own jobs (like Horizon / Telescope): you only
    | need Laravel's scheduler running. Times are cron expressions; set
    | RECOMMENDER_SCHEDULE=false to schedule them yourself.
    */
    'schedule' => [
        'enabled' => env('RECOMMENDER_SCHEDULE', true),
        'scores' => '*/10 * * * *',     // recommender:update-scores
        'neighbours' => '30 3 * * *',   // recommender:build-neighbours
        'prune' => '30 4 * * *',        // recommender:prune
    ],

    /* Database connection for the package tables (null = default). */
    'database_connection' => env('RECOMMENDER_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Defaults applied to every feed (each feed may override any key)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'page_size' => 12,

        // Per-user ranked list
        'feed_size' => 200,          // ids kept per user
        'feed_ttl' => 1800,          // seconds before the list is rebuilt
        // Speed checks (doctor + dashboard): app code slower than slow_ms per call
        // (sources, boosters, recommendable scope / filter, cards, groups, tail),
        // SQL slower than slow_query_ms while the jobs run, and job runs longer
        // than these limits are reported. Only slow events are stored (7 days).
        'slow_ms' => 200,
        'slow_query_ms' => 1000,
        'slow_scores_seconds' => 300,      // update-scores is scheduled every 10 minutes
        'slow_neighbours_seconds' => 1800,

        'min_rebuild_interval' => 60, // page 1 may rebuild an exhausted list at most this often
        'source_limit' => 200,       // max candidates asked from each source

        // "Already seen" set per user
        'seen_ttl' => (int) env('RECOMMENDER_SEEN_TTL', 259200), // seconds, 3 days
        'seen_max' => (int) env('RECOMMENDER_SEEN_MAX', 200),    // most recent N ids kept (packed, ~1.5 KB)

        // Shared fallback list (guests, kill switch, build failures)
        'trending_sources' => [      // sources that work without a user
            \SolutionForest\Recommender\Sources\TrendingSource::class => 1.0,
        ],
        'trending_size' => 200,
        'trending_ttl' => 600,

        // Rendered card cache
        'card_ttl' => 3600,
        'card_variants_max' => 20,  // cards_vary_by: variants kept per card (oldest dropped)
        // Cache cards separately per attribute, e.g. ['locale'] or ['locale', 'tenant']
        // ('locale' defaults to app()->getLocale()). Empty = one card for everyone.
        'cards_vary_by' => [],

        // Multi-tenant / per-context feeds: attributes that change WHICH items
        // appear (passed as `attributes:`). The ranked list, the shared trending
        // list and the background build are then kept per value, so a user in
        // tenant A never gets tenant B's items. Empty = one list per user.
        // `tenant` also splits the seen-set; `device` / `locale` do not, so a
        // user sees no repeats across devices (override with 'seen_vary_by').
        'list_vary_by' => [],

        // Catalog tail (feeds with a 'model'): when ranked and trending items
        // run out, keep serving the newest items the recommendable scope allows.
        'tail' => true,
        'tail_order' => null,        // column, default: created_at (or the key)

        // Protection against duplicate concurrent builds for the same user
        'build_lock_seconds' => 10,
        'build_lock_wait' => 2,

        // Ranking — see docs/tuning.md ("Fine-tuning weights").
        // Personal feeds: keep personal sources high; freshness sources / exploration low.
        // More recency: raise a freshness source + exploration_ratio (users' feeds will look more alike).
        'sources' => [               // [CandidateSource::class => weight]
            \SolutionForest\Recommender\Sources\CoInteractionSource::class => 0.6,
            \SolutionForest\Recommender\Sources\TrendingSource::class => 0.4,
        ],
        'boosters' => [],            // [Booster::class => weight]
        'exploration_sources' => [], // [CandidateSource::class, ...]
        'exploration_ratio' => 0.15, // share of slots reserved for exploration (interleave, not a blend weight)
        'diversity' => [],           // ['shop' => 2, 'writer' => 1] max per page
        'jitter' => 0.0,             // randomness per list build: 0 = pure ranking … 1 = uniform random order

        // Interaction weighting (used by update-scores)
        // Any event strings your InteractionSource yields (not only view/like) — e.g. add 'share' => 6.0.
        'events' => ['view' => 1.0, 'like' => 4.0],
        'positive_events' => ['like'],
        'exposure_events' => ['view'],
        // Count an item as an impression the first time it is shown to a user (within
        // the seen window; guests: once per page). Written after the response.
        // Quality then uses positive ÷ impressions instead of ÷ exposure.
        'impressions' => false,
        // Guest pages have no seen-set, so each reload counts again; off by
        // default so anonymous traffic cannot push an item's quality down.
        'impressions_guests' => false,
        // 'impressions_after_response' => true,   // false = write inline
        'half_life_days' => 7,
        // First run only walks this many days; widen for historical imports (docs/operations.md).
        'scores_backfill_days' => 30,
        'scores_slice_minutes' => 60,    // update-scores commits per time slice (bounded memory, resumable)
        'scores_retention_days' => 180,  // prune: drop score rows with no event for this long

        // Co-interaction neighbours (used by build-neighbours)
        // Too short / sparse → CoInteractionSource missing from explain.
        'history_days' => 90,
        'history_per_user' => 30,
        'recent_items' => 20,
        'neighbours_max' => 30,
        'neighbours_min_co_count' => 2,
        'neighbours_memory_limit_mb' => 512, // per pass; above it the job restarts with 2x shards
        'neighbours_max_shards' => 64,
        'similar_ttl' => 3600,           // similar(): cached ids per item (a rebuild switches over at once)

        // Batch sizes for bulk writes / deletes (never row by row)
        'write_chunk' => 500,
        'delete_chunk' => 5000,

        // What you recommend + where interactions live (simple feeds need only these)
        'model' => null,             // Eloquent model; ids are its keys (int, UUID, ULID)
        'interactions' => null,      // ['post_likes' => 'like', ...] table => event, or an InteractionSource class
        'item_column' => null,       // item id column in those tables (default: {model}_id)
        'user_column' => 'user_id',
        'timestamp_column' => 'created_at',
        'history_events' => null,    // events used for "similar items" (default: positive_events)
        'interactions_connection' => null,

        // Optional custom classes (resolved from the container). When null and
        // 'model' is set: filter = model's recommendable scope, cards =
        // toRecommendationCard() / toArray(), groups = model attributes.
        'filter' => null,            // ItemFilter
        'cards' => null,             // CardTransformer
        'groups' => null,            // DiversityGroups
    ],

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    | Sensible weights for a kind of product, picked with 'preset' => '…' on a
    | feed. Order: package defaults < 'defaults' < preset < the feed's own keys,
    | so anything you set on the feed still wins. Add your own here.
    */
    'presets' => [
        // Shops / catalogs: purchases matter most, slower decay, little exploration.
        'catalog' => [
            'sources' => [
                \SolutionForest\Recommender\Sources\CoInteractionSource::class => 0.55,
                \SolutionForest\Recommender\Sources\TrendingSource::class => 0.35,
            ],
            'boosters' => [\SolutionForest\Recommender\Boosters\QualityBooster::class => 0.20],
            'exploration_sources' => [\SolutionForest\Recommender\Sources\TrendingSource::class],
            'exploration_ratio' => 0.05,
            'events' => ['view' => 1.0, 'like' => 4.0, 'cart' => 6.0, 'purchase' => 10.0],
            'positive_events' => ['like', 'cart', 'purchase'],
            'exposure_events' => ['view'],
            'half_life_days' => 14,
        ],
        // Posts / UGC / reviews: fast decay, more discovery, comments and shares count.
        'social' => [
            'sources' => [
                \SolutionForest\Recommender\Sources\CoInteractionSource::class => 0.50,
                \SolutionForest\Recommender\Sources\TrendingSource::class => 0.30,
            ],
            'boosters' => [\SolutionForest\Recommender\Boosters\QualityBooster::class => 0.15],
            'exploration_sources' => [\SolutionForest\Recommender\Sources\TrendingSource::class],
            'exploration_ratio' => 0.10,
            'events' => ['view' => 1.0, 'like' => 4.0, 'comment' => 6.0, 'share' => 8.0],
            'positive_events' => ['like', 'comment', 'share'],
            'exposure_events' => ['view'],
            'half_life_days' => 3,
        ],
        // Time-bound items (events, deals): joins matter most, quick decay.
        'events' => [
            'sources' => [
                \SolutionForest\Recommender\Sources\CoInteractionSource::class => 0.50,
                \SolutionForest\Recommender\Sources\TrendingSource::class => 0.35,
            ],
            'boosters' => [\SolutionForest\Recommender\Boosters\QualityBooster::class => 0.15],
            'exploration_sources' => [\SolutionForest\Recommender\Sources\TrendingSource::class],
            'exploration_ratio' => 0.08,
            'events' => ['view' => 1.0, 'like' => 4.0, 'join' => 10.0],
            'positive_events' => ['like', 'join'],
            'exposure_events' => ['view'],
            'half_life_days' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feeds
    |--------------------------------------------------------------------------
    | Simplest feed — no classes needed:
    |
    | 'posts' => [
    |     'preset' => 'social',            // optional: catalog | social | events
    |     'model' => \App\Models\Post::class,
    |     'interactions' => ['post_likes' => 'like', 'post_views' => 'view'],
    | ],
    |
    | Fully custom feed:
    |
    | 'reviews' => [
    |     'sources' => [
    |         \SolutionForest\Recommender\Sources\CoInteractionSource::class => 0.35,
    |         \SolutionForest\Recommender\Sources\TrendingSource::class => 0.10,
    |     ],
    |     'boosters' => [\SolutionForest\Recommender\Boosters\QualityBooster::class => 0.20],
    |     'interactions' => \App\Recommendation\ReviewInteractionSource::class,
    |     'filter' => \App\Recommendation\VisibleReviewFilter::class,
    |     'cards' => \App\Recommendation\ReviewCardTransformer::class,
    | ],
    */
    'feeds' => [],
];
