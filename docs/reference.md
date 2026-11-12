# Reference

Contracts, serving API, jobs, and config knobs.

## Contracts

All classes resolve from the container (constructor injection works).

| Contract | Required | Called by | Must be |
|---|---|---|---|
| `InteractionSource` | for jobs | `update-scores`, `build-neighbours`, `CoInteractionSource` | streaming bulk reads |
| `ItemFilter` | recommended | every build | **one** query for all ids |
| `CardTransformer` | yes | card cache misses | one query; no viewer-specific fields |
| `CandidateSource` | your signals | every build | indexed queries + `LIMIT` |
| `Booster` | optional | every build | one query on candidate ids |
| `DiversityGroups` | optional | every build | one query |

Rules that matter:

- `$context->userId` is `null` for shared trending — handle guests.
- Cards are cached per item and shared. Merge “liked by me” in the controller.
- `attributes` on `page()` → `$context->attribute('user')`. Queued `warm()` has no attributes.

Built-ins:

| Class | Type |
|---|---|
| `Sources\CoInteractionSource` | neighbours of recent items |
| `Sources\TrendingSource` | `ORDER BY hot DESC LIMIT n` |
| `Boosters\QualityBooster` | Wilson lower bound |

## Serving a feed

```php
use SolutionForest\Recommender\Facades\Recommender;

$result = Recommender::page('posts', $user?->id, page: $page, perPage: 10, attributes: ['user' => $user]);

$result->items;     // cards
$result->ids;
$result->hasMore;
$result->total();   // for LengthAwarePaginator
$result->origin;    // 'personal' | 'trending'

$paginator = $result->toPaginator(['path' => request()->url()]);
return response()->json($result->toPaginatorArray(['path' => request()->url()]));
```

`page()` returns `FeedPage` (seen-set / rebuild). It does **not** use SQL `OFFSET`; the paginator is a view over the cached ranked list.

Rules:

- Page 1 reuses a fresh list (`feed_ttl`); rebuilds when stale or nearly fully seen.
- Later pages skip the seen-set.
- Personal list empty → continue with shared trending. Page 1 never empty.
- Guests (`null`) get trending, offset-paginated.

Other entry points:

```php
Recommender::warm('posts', $userId);
Recommender::forgetCard('posts', $id);
Recommender::forgetUser('posts', $userId);
Recommender::trending('posts');
Recommender::explain('posts', $userId);
```

## Background jobs

| Cadence | Command | Why |
|---|---|---|
| ~10 min | `recommender:update-scores` | New events → `hot` / quality (watermarked) |
| Nightly | `recommender:build-neighbours` | Item–item graph |
| Daily | `recommender:prune` | Idle score rows |

```php
$schedule->command('recommender:update-scores')->everyTenMinutes()->withoutOverlapping()->onOneServer();
$schedule->command('recommender:build-neighbours')->dailyAt('03:30')->withoutOverlapping(120)->onOneServer();
$schedule->command('recommender:prune')->dailyAt('04:30')->withoutOverlapping(60)->onOneServer();
```

| Command | Reads | Writes | Cheap because |
|---|---|---|---|
| `update-scores` | events since watermark | `rec_item_scores` | time slices; one txn per slice |
| `build-neighbours` | capped history | `rec_item_neighbours` | shards; versioned activate |
| `prune` | scores | deletes | PK chunks |
| `explain` | – | – | debug breakdown |
| `status` | tables + state | – | watermark lag, counts, lock support |

```bash
php artisan queue:work --queue=recommender
```

`BuildFeed` is `ShouldBeUnique` per feed+user. Live traffic does not rebuild the world on every scroll.

## Configuration

Top level:

| Key | Env | Default | Purpose |
|---|---|---|---|
| `enabled` | `RECOMMENDER_ENABLED` | `true` | Kill switch → trending only |
| `cache_store` | `RECOMMENDER_CACHE_STORE` | default | lists, seen, cards, locks |
| `prefix` | `RECOMMENDER_PREFIX` | `rec` | cache key prefix |
| `queue` / `queue_connection` | `RECOMMENDER_QUEUE` / `…_CONNECTION` | `recommender` / default | `BuildFeed` |
| `database_connection` | `RECOMMENDER_DB_CONNECTION` | default | package tables |

Per feed (defaults, overridable in `feeds.*`):

| Key | Default | Purpose |
|---|---|---|
| `page_size` | 12 | page + diversity |
| `feed_size` | 200 | ids kept per user |
| `feed_ttl` | 1800 | seconds before rebuild |
| `min_rebuild_interval` | 60 | throttle exhausted page-1 rebuilds |
| `source_limit` | 200 | candidates per source |
| `seen_ttl` / `seen_max` | 3 days / 200 | seen-set |
| `trending_sources` | `[TrendingSource => 1]` | guest / fallback |
| `trending_size` / `trending_ttl` | 200 / 600 | shared list |
| `card_ttl` | 3600 | card cache |
| `build_lock_seconds` / `build_lock_wait` | 10 / 2 | per-user build lock |
| `sources` / `boosters` | – | `class => weight` |
| `exploration_sources` / `exploration_ratio` | – / 0.15 | exploration |
| `diversity` | – | `group => max per page` |
| `events` | view 1, like 4 | trending weights |
| `positive_events` / `exposure_events` | like / view | quality |
| `half_life_days` | 7 | trending decay |
| `scores_backfill_days` | 30 | first `update-scores` window |
| `scores_slice_minutes` | 60 | score commit size |
| `scores_retention_days` | 180 | prune threshold |
| `history_days` / `history_per_user` | 90 / 30 | neighbour input |
| `recent_items` | 20 | co-interaction seeds |
| `neighbours_max` / `neighbours_min_co_count` | 30 / 2 | neighbour output |
| `neighbours_memory_limit_mb` / `neighbours_max_shards` | 512 / 64 | memory guard |
| `write_chunk` / `delete_chunk` | 500 / 5000 | bulk write sizes |
| `interactions`, `filter`, `cards`, `groups` | – | your classes |

## Cache store & locks

Configured by `recommender.cache_store` / `RECOMMENDER_CACHE_STORE` (empty → app `CACHE_STORE`).

Build locks need a store that implements Laravel’s `LockProvider`. Not Redis-only.

| Store | Locks | Typical use |
|---|---|---|
| **redis** | Yes | Production default — fast lists + atomic locks |
| **database** | Yes on Laravel **11–13** if `cache` + `cache_locks` tables exist (`php artisan cache:table`) | Small / mid prod without Redis |
| **array** / **file** | Yes in recent Laravel (single process / local) | Local lab & CI |
| Custom store without `LockProvider` | No | Package falls back to unlocked builds (last write wins) |

```env
RECOMMENDER_CACHE_STORE=redis
# RECOMMENDER_CACHE_STORE=database
```

Verify: `php artisan recommender:status` (shows store name + locks yes/fallback).

## Testing the package

```bash
composer install
composer test
composer analyse
```

| Suite | Covers |
|---|---|
| `tests/Unit/ScoringTest.php` | blend, explore, diversity, Wilson, decay |
| `tests/Feature/RecommenderPageTest.php` | paging, cache, filters, guests, kill switch, unlocked lock fallback |
| `tests/Feature/CacheStoreTest.php` | array / file / database / redis stores provide locks + serve pages |
| `tests/Feature/MaintenanceTest.php` | scores, neighbours, versions, event weight → `hot` order |
| `tests/Feature/ScaleTest.php` | shards, memory, bulk writes |
| `tests/Feature/BlogExampleTest.php` | sample app E2E + sponsored source/booster |

CI: PHPStan + PHP 8.3–8.4 × Laravel 11–13 (SQLite) + Laravel 13 / MySQL 8.
