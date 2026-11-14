# Upgrade guide

## From 0.10.7 to 0.10.8

Nothing to change (CI and docs only).

## From 0.10.6 to 0.10.7

Nothing to change. If a feed points `filter`, `cards`, `groups` or `interactions` at a class that does not implement the matching contract, you now get an `InvalidArgumentException` with the feed name instead of a `TypeError`.

## From 0.10.5 to 0.10.6

Nothing to change (docs only).

## From 0.10.4 to 0.10.5

- **App timezone not UTC, on Laravel 11–13:** stored scores were counted wrongly (too high east of UTC, events missing west of UTC). Rebuild them once per feed after upgrading: `php artisan recommender:rebuild-scores {feed} --days=30` (use the window you need). Impressions are kept. Laravel 10 apps and UTC apps need nothing.
- **Guest impressions:** with `'impressions' => true`, guest pages are no longer counted. To keep the old behaviour set `'impressions_guests' => true`, and rate-limit the guest feed route. Counts already inflated by guest reloads are not changed by `rebuild-scores`.
- **`cards_vary_by`:** at most `card_variants_max` (20) variants are cached per card. Raise it if you really serve more locales / tenants per card.

## From 0.10.3 to 0.10.4

Nothing to change (docs and comments). If you copied the blog example's `SponsoredShopSource` / `SponsoredShopBooster`, they are now `SponsoredAuthorSource` / `SponsoredAuthorBooster` and read `sponsored_author_ids` only.

## From 0.10.2 to 0.10.3

Nothing to change. A cold `similar()` build now runs one more query (the `recommendable` scope or your `filter`); warm calls still use none. If you added an attribute to `cards_vary_by` only as a workaround for the `similar()` leak, you can remove it.

## From 0.10.1 to 0.10.2

Nothing to change (docs and config comments only).

## From 0.10.0 to 0.10.1

Nothing to change. `similar()` switches to new neighbours reliably after `build-neighbours`, also when web servers do not share the builder's cache store.

## From 0.9.0 to 0.10.0

Nothing to change. `$post->similar(6)` / `Recommender::similar()` are new and optional; they read the neighbours `build-neighbours` already stores (no migration).

## From 0.8.5 to 0.9.0

Nothing to change. Laravel 10 apps can now install the package. `jitter` defaults to `0`, so existing feeds keep their order.

## From 0.8.4 to 0.8.5

Nothing to change (docs and test output only).

## From 0.8.3 to 0.8.4

Nothing to change. `Recommender` is now a scoped binding (fresh per request / queue job). If you bound your own singleton over it, bind it with `scoped()` too.

## From 0.8.2 to 0.8.3

Nothing to change. Internal refactor; the public API and the facade are the same. If you extended `Recommender` and overrode private methods, see the class map in CONTRIBUTING.md.

## From 0.8.0 / 0.8.1 to 0.8.2

Nothing to change. `RECOMMENDER_ENABLED=false` now also pauses the scheduled jobs and skips seen-list / impression writes (before, only personal ranking stopped); trending keeps serving from the last scores. On the database cache store, the next `recommender:prune` (daily, scheduled for you) removes the package's expired cache rows once — the first run may delete many.

If the app has a working Redis store and you did not set `RECOMMENDER_CACHE_STORE`, the recommender uses Redis since 0.8.1; users may see a few repeats once while new seen lists fill. Set `RECOMMENDER_CACHE_STORE=database` to keep the previous store.

## From 0.7.x to 0.8.0

Only if you use `'impressions' => true`:

- An impression now approximates **the first serve to this user within the seen window**; guests count per serve. New counts generally accumulate more slowly than the old "every serve" counts, affecting `rate` / `quality`. Existing numbers are not rewritten; to compare feeds fairly, compare periods after the upgrade. Impressions cannot be replayed from your tables, so `rebuild-scores` keeps them. See [limitations and costs](docs/operations.md#seen-memory-and-impressions).
- Writes happen after the response in HTTP requests. Set `'impressions_after_response' => false` to keep them inline.
- Nothing else to change.

## From 0.7.0 to 0.7.1

Nothing to change. `recordInteraction()` is optional and additive.

## From 0.6.x to 0.7.0

- **Signed-in JSON changed.** `recommendations()` / returning a `FeedPage` now gives Laravel's simple-paginator shape for a signed-in user: no `total`, `last_page` or numbered `links`. Use `next_page_url` / `next_cursor` (and `has_more`) to load more. Guests are unchanged. If a frontend still needs `total` / `last_page`, return `$page->toPaginator()->toArray()` yourself.
- **Multi-tenant apps:** if you pass an attribute such as `tenant` that changes which items appear, add it to the feed's `list_vary_by` (`'list_vary_by' => ['tenant']`). Without it a list cached under one value is served under another. Existing cached lists stay valid for feeds that do not set it.
- Nothing else to change.

## From 0.6.2 to 0.6.3

Nothing to change. Links in paginator JSON now keep your other query parameters. If you relied on the code default `seen_max` of 500 (only when `seen_max` was missing from your config), set it explicitly; the documented default was always 200.

## From 0.6.0 / 0.6.1 to 0.6.2

Nothing to change. If your item ids are zero-padded strings (`"000123"`), they now stay strings everywhere (before, they could become `123`); cached lists heal within `feed_ttl` (or change `RECOMMENDER_PREFIX` to start with a fresh cache).

## From 0.5.x to 0.6.0

- **The package now schedules its own jobs.** If you added `Schedule::command('recommender:…')` lines yourself, delete them — or keep them and set `RECOMMENDER_SCHEDULE=false`. (Running both is harmless thanks to `withoutOverlapping()`, just wasteful.)
- Optional: replace `recommendations('posts', $user)` with `Post::recommendedFor($user)` on models that use `Recommendable`.
- Your published config keeps working; newly published configs are short.

## From 0.4.x to 0.5.0

Nothing to change. Optional: replace hand-tuned `sources` / `boosters` / `events` with `'preset' => 'catalog' | 'social' | 'events'` and keep only the keys you really changed. `make-feed` now writes a `preset` line for the blog / products / events / reviews wizard presets.

## From 0.3.x to 0.4.0

1. Run `php artisan migrate` — adds `impressions` / `last_shown_at` to `rec_item_scores` and the `rec_item_daily` table.
2. Optional: `'impressions' => true` on a feed to count served items (quality then uses positive ÷ impressions).
3. Daily counters start filling from the next `update-scores` run. To backfill them, run `php artisan recommender:rebuild-scores {feed} --days=30`.

## From 0.2.x to 0.3.0

- Feeds with a `model` now continue past ranked and trending items with the newest items your `recommendable` scope allows, so `has_more` stays `true` longer. To keep the old cut-off set `'tail' => false` on the feed (or in `defaults`).
- Paginator JSON gains a `next_cursor` key; responses to `?cursor=` requests use the cursor-paginator shape.
- If you copied the blog example's `InfiniteScrollFeed`, you can delete it.

## From 0.1.x to 0.2.0

Nothing to change in your app.

- `recommender:make-feed` now registers a config-only feed and prints the model methods to add. Scripts that relied on it generating classes should add `--classes`.
- `explain()` rows have three more keys (`final`, `cached`, `exploration`); code that reads rows by key is unaffected.
- To recompute scores, use `recommender:rebuild-scores {feed}` instead of the manual delete-and-replay steps.

## From 0.0.x to 0.1.0

Estimated time: 5 minutes. Existing class-based feeds keep working; check the four behaviour changes below.

### 1. Default sources (likely to affect you if a feed has no `sources`)

A feed without `sources` used to rank by trending only. It now blends `CoInteractionSource` (0.6) and `TrendingSource` (0.4). To keep the old behaviour, set it explicitly:

```php
'sources' => [\SolutionForest\Recommender\Sources\TrendingSource::class => 1.0],
```

Feeds that already list `sources` (including your published `defaults.sources`) are unaffected.

### 2. `page()` reads `?page=` when no page is given

`Recommender::page($feed, $user)` without a page number now uses the request's `?page=` (like `paginate()`), not `1`. Calls that pass a page are unaffected.

### 3. Paginator `path` defaults to the current URL

`FeedPage::toPaginator()` / `toPaginatorArray()` without a `path` option now build links from the current URL instead of `/`. Pass `['path' => …]` to keep a fixed path.

### 4. Dashboard route

A read-only dashboard is registered at `/recommender`. It is open in the `local` environment only; elsewhere it returns 403 until you define the gate:

```php
Gate::define('viewRecommender', fn ($user) => $user->is_admin);
```

If `/recommender` is already one of your routes, move the dashboard with `RECOMMENDER_DASHBOARD_PATH=…`, or turn it off with `RECOMMENDER_DASHBOARD=false`.

### Optional: simplify your feeds

If your interaction tables have a user id, an item id and a timestamp, you can delete the generated `InteractionSource`, `ItemFilter` and `CardTransformer` classes and configure the feed instead:

```php
'posts' => [
    'model' => App\Models\Post::class,
    'interactions' => ['post_likes' => 'like', 'post_views' => 'view'],
],
```

Move the filter's rules into `scopeRecommendable(Builder $query, Context $context)` on the model, and the card into `toRecommendationCard()`. Then run `php artisan recommender:doctor` to confirm the tables and columns match. Scores and neighbours already built stay valid; there is no data migration. See the [tutorial](docs/tutorial.md).

### New config keys

Publishing the config again is optional — missing keys fall back to the package defaults. New keys: `dashboard.*`, and per feed `model`, `item_column`, `user_column`, `timestamp_column`, `history_events`, `interactions_connection`.
