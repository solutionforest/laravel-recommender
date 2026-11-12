<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    | When disabled, Recommender::page() serves the shared trending list only:
    | one cache read per request, no per-user work.
    */
    'enabled' => env('RECOMMENDER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    | Store for ranked lists, seen-sets, cards, trending, and per-user build locks.
    | null / empty = Laravel's default CACHE_STORE.
    |
    | Any store that implements LockProvider works for build locks, including:
    |   - redis     (recommended in production — fast, atomic)
    |   - database  (fine on Laravel 11–13 if cache + cache_locks tables exist)
    |   - array/file (local / single-process; locks exist but are not multi-server)
    |
    | If the store cannot provide locks, the package falls back to building
    | without a lock (last write wins). Check: php artisan recommender:status
    |
    | Examples:
    |   RECOMMENDER_CACHE_STORE=redis
    |   RECOMMENDER_CACHE_STORE=database
    |   (omit) → use CACHE_STORE
    */
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
        'min_rebuild_interval' => 60, // page 1 may rebuild an exhausted list at most this often
        'source_limit' => 200,       // max candidates asked from each source

        // "Already seen" set per user (replaces session based exclusion)
        'seen_ttl' => 259200,        // 3 days
        'seen_max' => 200,           // most recent N ids kept (stored packed, ~1.5 KB)

        // Shared fallback list (guests, kill switch, build failures)
        'trending_sources' => [      // sources that work without a user
            \SolutionForest\Recommender\Sources\TrendingSource::class => 1.0,
        ],
        'trending_size' => 200,
        'trending_ttl' => 600,

        // Rendered card cache
        'card_ttl' => 3600,

        // Protection against duplicate concurrent builds for the same user
        'build_lock_seconds' => 10,
        'build_lock_wait' => 2,

        // Ranking — see README "Fine-tuning weights".
        // Inspiration/segments: keep personal sources high; Fresh* / exploration low.
        // Chronological spice: raise Fresh* + exploration_ratio (cohorts will look more alike).
        'sources' => [               // [CandidateSource::class => weight]
            \SolutionForest\Recommender\Sources\CoInteractionSource::class => 0.6,
            \SolutionForest\Recommender\Sources\TrendingSource::class => 0.4,
        ],
        'boosters' => [],            // [Booster::class => weight]
        'exploration_sources' => [], // [CandidateSource::class, ...]
        'exploration_ratio' => 0.15, // share of slots reserved for exploration (interleave, not a blend weight)
        'diversity' => [],           // ['shop' => 2, 'writer' => 1] max per page

        // Interaction weighting (used by update-scores)
        // Any event strings your InteractionSource yields (not only view/like) — e.g. add 'share' => 6.0.
        'events' => ['view' => 1.0, 'like' => 4.0],
        'positive_events' => ['like'],
        'exposure_events' => ['view'],
        'half_life_days' => 7,
        // First run only walks this many days; widen for historical imports (README checklist).
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
    | Feeds
    |--------------------------------------------------------------------------
    | Simplest feed — no classes needed:
    |
    | 'posts' => [
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
