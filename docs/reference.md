# Reference

Contracts, serving API, jobs, and config knobs. For worked examples of custom sources, boosters, classes and several feeds, see [advanced usage](advanced.md).

## What is `Context`?

`SolutionForest\Recommender\Support\Context` is the small bag every **source / booster / filter / groups** method receives. It is **not** Laravel’s HTTP request — it answers “who are we ranking for on this build?”

```php
public function candidates(Context $context, int $limit): array
{
    if (! $context->userId) {
        return []; // guest → no personal rule; trending handles guests
    }

    // …
}
```

| You write | Comes from |
|---|---|
| `$context->userId` | the user given to `recommendations('posts', $user)` / `Recommender::page('posts', $user)` (`null` = guest) |
| `$context->feed` | Feed config for this name (`feeds.posts` in `config/recommender.php`) |
| `$context->feed->get('sponsored_shop_ids')` | Custom keys you put on that feed |
| `$context->seen` | Ids already served (usually ignore inside sources) |
| `$context->attribute('user')` | the user model, when you pass a model (or `attributes: ['user' => $user]`) |
| `$context->now()` | Clock for “last 7 days / upcoming” (injectable in tests) |
| `$context->isGuest()` | Shortcut for `$context->userId === null` |

Wire the user once at the edge:

```php
recommendations('posts', $request->user()); // id → $context->userId, model → $context->attribute('user')
```

Then sources read `$context->userId` (or `$context->attribute('user')` when they need the full model). Queued `warm()` has **no** `attributes` — only `userId`.

## Contracts

All classes resolve from the container (constructor injection works).

**Most feeds need none of them.** Give the feed a `model` and an `interactions` table map and the package supplies the interaction reader, filter, cards and diversity groups:

```php
'posts' => [
    'model' => \App\Models\Post::class,
    'interactions' => ['post_likes' => 'like', 'post_views' => 'view'],
],
```

| Instead of | Use |
|---|---|
| `InteractionSource` | `'interactions' => [table => event]`. Each table has `user_id`, `{model}_id`, `created_at`, `id`. Override with `user_column` / `item_column` / `timestamp_column`, or per table: `'bookmarks' => ['event' => 'like', 'item' => 'bookmarkable_id', 'user' => …, 'timestamp' => …, 'key' => …]`. Neighbours use `history_events` (default `positive_events`). Ids are passed through: integers, UUIDs, ULIDs. |
| `ItemFilter` | the model's optional `scopeRecommendable(Builder $query, Context $context)` — one `whereKey($ids)` query |
| `CardTransformer` | the model's optional `toRecommendationCard(): array`, else `toArray()`. The scope runs as a guest here because cards are shared. |
| `DiversityGroups` | `diversity` keys are read as model attributes: `'diversity' => ['author_id' => 2]` |

Add `use SolutionForest\Recommender\Eloquent\Recommendable;` to the model to drop cached cards on save / delete. A class set in `interactions` / `filter` / `cards` / `groups` always wins.

| Contract | Required | Called by | Must be |
|---|---|---|---|
| `InteractionSource` | for jobs | `update-scores`, `build-neighbours`, `CoInteractionSource` | streaming bulk reads |
| `ItemFilter` | recommended | every build | **one** query for all ids |
| `CardTransformer` | yes | card cache misses | one query; no viewer-specific fields |
| `CandidateSource` | your signals | every build | indexed queries + `LIMIT` |
| `Booster` | optional | every build | one query on candidate ids |
| `DiversityGroups` | optional | every build | one query |

Rules that matter:

- `$context->userId` is `null` for shared trending — handle guests (see [What is Context?](#what-is-context)).
- Cards are cached per item and shared. Merge “liked by me” in the controller.
- Prefer `$context->attribute('user')` over re-querying the user when you passed it via `page()`.

Built-ins:

| Class | Type |
|---|---|
| `Sources\CoInteractionSource` | neighbours of recent items |
| `Sources\TrendingSource` | `ORDER BY hot DESC LIMIT n` |
| `Boosters\QualityBooster` | Wilson lower bound |

## Serving a feed

The shortest route returns the page; it renders as paginator JSON and reads `?page=` itself:

```php
Route::get('/posts/for-you', fn (Request $request) => Post::recommendedFor($request->user()));   // model with `use Recommendable`
Route::get('/posts/for-you', fn (Request $request) => recommendations('posts', $request->user())); // same, by feed name
```

`recommendations($feed, $user, $page = null, $perPage = null, $attributes = [])` is the same as `Recommender::page()`. Pass the user model (it becomes `$context->attribute('user')`), an id, or `null` for guests.

```php
use SolutionForest\Recommender\Facades\Recommender;

$result = Recommender::page('posts', $user, perPage: 10);

$result->items;     // cards
$result->ids;
$result->hasMore;
$result->total();   // for LengthAwarePaginator
$result->origin;    // 'personal' | 'trending'

$paginator = $result->toPaginator(['path' => request()->url()]);
return response()->json($result->toPaginatorArray(['path' => request()->url()]));
```

`page()` returns `FeedPage` (seen-set / rebuild). It does **not** use SQL `OFFSET`; the paginator is a view over the cached ranked list.

Cards and ranked lists are cached. Invalidate an edited card with `forgetCard`; changing a list or visibility rule may also require invalidating the user and trending lists. A build-time filter alone does not recheck every cached card on every request. Do not rely on it as the sole enforcement of immediate access revocation.

Rules:

- Page 1 reuses a fresh list (`feed_ttl`); rebuilds when stale or nearly fully seen.
- Later personal pages skip IDs in the seen-set; page numbers are metadata, not stable offsets. Concurrent requests or another device for the same user share this state.
- A short personal list is topped up from shared trending when possible. An empty candidate pool, filtering or missing cards can still yield an empty page.
- Guests (`null`) get trending, offset-paginated.

Other entry points:

```php
Recommender::warm('posts', $user);          // model or id
Recommender::forgetCard('posts', $id);
Recommender::forgetModel($post);             // every feed whose 'model' is Post (the Recommendable trait does this on save / delete)
Recommender::forgetUser('posts', $user);     // also clears the seen-set
Recommender::forgetTrending('posts');
Recommender::trending('posts');
Recommender::explain('posts', $userId);    // see "Explain" below
```

### Config file

`php artisan vendor:publish --tag=recommender-config` (and `make-feed` / `bootstrap`) publish a **short** `config/recommender.php`: `enabled`, `cache_store` and `feeds`. Every other key keeps its package default until you add it; the full list is in [configuration](#configuration), or publish the full file with `--tag=recommender-config-full`.

`$page->models()` returns the page as Eloquent models in ranked order (one query) when you prefer rendering models over cards.

Item ids may be integers, UUIDs, ULIDs or any string (commas included). Plain numbers come back as integers; zero-padded strings such as `"000123"` stay strings.

`Model::recommendedFor($user, ?$feed)` (from `Recommendable`) uses the one feed whose `model` is that class; pass `$feed` when several do. When a first page comes back empty, one log notice per feed per hour points at `recommender:doctor`.

### Presets

`'preset' => 'catalog' | 'social' | 'events'` on a feed sets sensible ranking in one line. Order: package defaults < your `defaults` < preset < the feed's own keys, so the feed always wins.

| Preset | For | Sources (Co / Trending) | Events (weight) | Positive | Half-life | Exploration |
|---|---|---|---|---|---|---|
| `catalog` | shops, catalogs | 0.55 / 0.35 + Quality 0.20 | view 1, like 4, cart 6, purchase 10 | like, cart, purchase | 14 days | 5% |
| `social` | posts, UGC, reviews | 0.50 / 0.30 + Quality 0.15 | view 1, like 4, comment 6, share 8 | like, comment, share | 3 days | 10% |
| `events` | events, deals, anything time-bound | 0.50 / 0.35 + Quality 0.15 | view 1, like 4, join 10 | like, join | 5 days | 8% |

Add your own under `presets` in `config/recommender.php` (e.g. `'jobs' => ['events' => ['view' => 1, 'apply' => 10], 'half_life_days' => 30]`). An unknown preset name fails with the list of available ones. `make-feed` writes the matching preset (blog / reviews → `social`, products → `catalog`, events → `events`).

### Infinite scroll: catalog tail and cursors

For a feed with a `model`, a page continues seamlessly: **ranked items → trending → the newest items the `recommendable` scope allows**, never repeating what the user has seen, until the catalog is exhausted (`has_more: false`). Pages made only of catalog items have `origin: 'tail'`. Turn it off with `'tail' => false`; order it by another column with `'tail_order' => 'published_at'`.

Every response has a `next_cursor`. Infinite-scroll clients can follow it instead of counting pages:

```text
GET /posts/recommended               → { data, current_page, …, next_cursor: "eyJwIjoyfQ" }
GET /posts/recommended?cursor=eyJwIjoyfQ → { data, path, per_page, next_cursor, next_page_url, prev_cursor, origin, has_more }
```

With `?cursor=` the response uses Laravel's cursor-paginator shape. `FeedPage::nextCursor()` / `toCursorArray()` give the same in PHP. Page numbers keep working.

A cursor means **"the next page of this feed for this user"**, not a frozen snapshot: personal paging follows the user's seen-set, which all their devices share, so items never repeat across devices. Cursors carry the feed name; a cursor from another feed is ignored (page 1).

### How good is an item?

```php
Recommender::stats('products', [12, 15]);           // or a model: Recommender::stats('products', $product)
Recommender::stats('products', $product, days: 7);  // last 7 days only
$product->recommendationStats('products');          // with the Recommendable trait
// [12 => ['positive' => 25, 'exposure' => 40, 'impressions' => 0, 'rate' => 0.625, 'quality' => 0.47, 'popularity' => 0.82, 'last_event_at' => '…'], …]
```

| Key | Meaning |
|---|---|
| `positive` / `exposure` | counts of your `positive_events` (likes, joins, purchases, comments…) and `exposure_events` (views) |
| `impressions` | times the item was served in a page (when `'impressions' => true`) |
| `rate` | positive ÷ exposure — or ÷ impressions when impressions are on (capped at 1 when likes outnumber recorded views); `null` when the denominator is 0 |
| `quality` | Wilson lower bound of the rate (0..1): a *confident* "how good". One like from one view (0.21) ranks below 25 from 40 (0.47) — the same score `QualityBooster` uses |
| `popularity` | time-decayed activity (`half_life_days`) relative to the hottest item right now (0..1) |

With `days`, counts cover the last N days (today included) from daily counters, so you can see whether an item is getting better or worse; `popularity` and `last_event_at` stay lifetime values. The dashboard's *Trending now* table shows lifetime quality, the last 7 days and a ▲ / ▼ against the last 30.

Works for anything you recommend — products, events, posts, or comments if comments are a feed's items. Event counts come from `update-scores`, so they lag by up to one run; impressions are live.

#### Impressions (shown, not just viewed)

Views your app records are only a proxy for "the user saw it". Turn on impressions and every item served in a page counts as shown:

```php
'posts' => [
    'model' => Post::class,
    'interactions' => [...],
    'impressions' => true,   // + quality = positive ÷ impressions
],
```

Each served page costs four small statements (insert missing rows, then atomic increments — safe under concurrent requests); a failure is logged and never breaks the page. `QualityBooster` and `stats()` then rate items by how often they were liked *when shown*. `rebuild-scores` keeps impressions (they cannot be replayed from your tables); `prune` keeps items that are still being shown.

### Hiding an item (visibility changes)

Lists are filtered when they are built, but **every page re-renders cards**, and an item without a card is skipped (the page is still filled from the next items). So hiding an item takes effect on the next request as long as its cached card is dropped:

| Feed setup | When an item is unpublished / deleted |
|---|---|
| `model` + `use Recommendable;` | nothing to do — saving or deleting the model forgets its card; the `recommendable` scope then hides it |
| `cards` class | call `Recommender::forgetCard('posts', $id)` (or `forgetModel($post)`), and make the transformer skip hidden items |

Viewer-specific rules (blocking a user) are applied by the filter at build time; call `Recommender::forgetUser('posts', $viewer)` to apply them at once.

### Explain

`Recommender::explain($feed, $user, $limit)` / `recommender:explain` / *Explain a user* in the dashboard run a fresh build without storing it. Each row:

| Key | Meaning |
|---|---|
| `score`, `sources`, `contributions` | blended score, each source's raw score, and each source / booster's share (they add up to `score`) |
| `final` | position after exploration and diversity in this fresh build (exploration is shuffled, so it can vary) |
| `cached` | position in the list the user is served right now; `null` = not in it or nothing cached |
| `exploration` | `true` for exploration-only items that reached the final top `$limit` (score 0) |

Rows are in blended-score order.

## Background jobs

| Cadence | Command | Why |
|---|---|---|
| ~10 min | `recommender:update-scores` | New events → `hot` / quality (watermarked) |
| Nightly | `recommender:build-neighbours` | Item–item graph |
| Daily | `recommender:prune` | Idle score rows |

The package registers these with Laravel's scheduler itself (`withoutOverlapping()->onOneServer()`), so you only run the scheduler. Change the times, or turn it off to schedule them yourself:

```php
'schedule' => [
    'enabled' => env('RECOMMENDER_SCHEDULE', true),
    'scores' => '*/10 * * * *',
    'neighbours' => '30 3 * * *',
    'prune' => '30 4 * * *',
],
```

Run Laravel's scheduler and use a shared lock-capable cache for `onOneServer()` across hosts. The maintenance commands run synchronously; the queue worker is needed when using `warm()` with an asynchronous queue.

| Command | Reads | Writes | Cheap because |
|---|---|---|---|
| `update-scores` | events since watermark | `rec_item_scores` | time slices; one txn per slice |
| `build-neighbours` | capped history | `rec_item_neighbours` | shards; versioned activate |
| `prune` | scores | deletes | PK chunks |
| `explain` | sources and boosters | – | fresh build: score breakdown, final position, cached position |
| `bootstrap {feed}` | – | config, `rec_*` | migrate → `make-feed` → first scores / neighbours → `doctor`; prints the route |
| `rebuild-scores {feed}` | events in `--days` window | `rec_item_scores` (one feed) | replays slices; `--dry-run`, confirmation, interruption-safe |
| `doctor` | config, schema, state | – | checks with fixes; non-zero exit on failure |
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
| `cards_vary_by` | `[]` | cache cards per attribute, e.g. `['locale']` (`locale` defaults to the app locale) |
| `tail` / `tail_order` | `true` / created_at | catalog tail after ranked + trending items (feeds with a `model`) |
| `impressions` | `false` | count served items; quality uses positive ÷ impressions |
| `build_lock_seconds` / `build_lock_wait` | 10 / 2 | per-user build lock |
| `sources` | `CoInteractionSource` 0.6, `TrendingSource` 0.4 | `class => weight` |
| `boosters` | – | `class => weight` |
| `exploration_sources` / `exploration_ratio` | – / 0.15 | exploration |
| `diversity` | – | `group => max per page` |
| `events` | `view` 1, `like` 4 (any names OK) | trending weights per event type |
| `positive_events` / `exposure_events` | like / view | quality (Wilson) |
| `half_life_days` | 7 | trending decay |
| `scores_backfill_days` | 30 | first `update-scores` window |
| `scores_slice_minutes` | 60 | score commit size |
| `scores_retention_days` | 180 | prune threshold |
| `history_days` / `history_per_user` | 90 / 30 | neighbour input |
| `recent_items` | 20 | co-interaction seeds |
| `neighbours_max` / `neighbours_min_co_count` | 30 / 2 | neighbour output |
| `neighbours_memory_limit_mb` / `neighbours_max_shards` | 512 / 64 | memory guard |
| `write_chunk` / `delete_chunk` | 500 / 5000 | bulk write sizes |
| `model` | – | Eloquent model the feed recommends (enables default filter / cards / groups) |
| `interactions` | – | `[table => event]` map, or an `InteractionSource` class |
| `item_column` / `user_column` / `timestamp_column` | `{model}_id` / `user_id` / `created_at` | interaction table columns |
| `history_events` | `positive_events` | events used for neighbours and recent items |
| `interactions_connection` | default | DB connection of the interaction tables |
| `filter`, `cards`, `groups` | – | optional classes; override the model defaults |

## Dashboard

Screenshots and the full list of checks: [dashboard.md](dashboard.md).

A single read-only page at `/recommender`, like Horizon: per-feed health (score lag, scored items, neighbours), configuration checks, the trending list and an **Explain a user** lookup. It refreshes itself every 10 s; no JavaScript build, no Livewire.

It is open in the `local` environment. Everywhere else, define who may see it:

```php
// AppServiceProvider::boot()
Gate::define('viewRecommender', fn ($user) => in_array($user->email, ['ops@example.com']));
```

| Config `recommender.dashboard.*` | Default | |
|---|---|---|
| `enabled` | `true` (`RECOMMENDER_DASHBOARD`) | register the route |
| `path` | `recommender` | URL prefix |
| `domain` | `null` | optional sub-domain |
| `middleware` | `['web']` | add `auth` etc.; the gate is always applied |
| `refresh` | `10` | seconds between live updates, `0` = off (paused while explaining) |

The same checks run in the terminal, with a non-zero exit code on failures (useful in deploy scripts):

```bash
php artisan recommender:doctor          # all feeds
php artisan recommender:doctor posts
```

Checks cover the model and its table, every interaction table and column, events without a weight, source/booster/filter/cards classes and contracts, score lag, neighbour build and cache locks.

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
| `tests/Feature/SimpleExampleTest.php` | config-only feed (`model` + interaction tables) E2E |
| `tests/Feature/DatabaseInteractionsTest.php` | table-driven interactions: UUIDs, per-table columns, user chunk paging |
| `tests/Feature/DashboardTest.php` | dashboard gate, health/checks/trending/explain, `recommender:doctor` |
| `tests/Feature/StressTest.php` | **volume**: 100k events / 2k users / 1k items — exact counts, bounded memory & queries, constant serving cost · **frequency**: per-minute score runs with bursts and boundary events (no loss, no double count), rapid-fire paging (no repeats, throttled rebuilds), edits between requests |

Stress tests run in the normal suite (~10 s on SQLite). Run them alone or bigger with `RECOMMENDER_STRESS_SCALE=5 vendor/bin/pest --group=stress`.

CI: PHPStan + PHP 8.3–8.4 × Laravel 11–13 (SQLite) + Laravel 13 / MySQL 8.
