# Upgrade guide

## From 0.7.x to 0.8.0

Only if you use `'impressions' => true`:

- An impression now means **first time shown to this user within the seen window**. New counts accumulate more slowly than the old "every serve" counts and `rate` / `quality` move up. Existing numbers are not rewritten; to compare feeds fairly, compare periods after the upgrade. (Impressions cannot be replayed from your tables, so `rebuild-scores` keeps them.)
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
