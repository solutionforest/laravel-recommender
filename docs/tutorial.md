# Create your first feed

This walkthrough uses a `posts` feed in an existing Laravel application. Replace its table and column names with yours. You need PHP 8.3+, Laravel 11–13, a working database and an item model such as `App\Models\Post`.

## 1. Check your data

For the generated blog scaffold, these are example fields (not tables the package creates):

| Table | Fields used by the generated classes |
|---|---|
| `posts` | `id`, `title`, `published`, `author_id`, `created_at` |
| `post_likes`, `post_views` | numeric `id`, `user_id`, `post_id`, `created_at` |

The scaffold assumes numeric user and item IDs, separate event tables, and an incrementing `id` for `lazyById`. Change the generated code if your schema differs, especially for UUIDs, nullable users, or a single events table. The item filter hides unpublished posts and the signed-in user's own posts by default. You are responsible for recording events and enforcing your app's visibility and authorization rules.

## 2. Install and scaffold

```bash
composer require solutionforest/laravel-recommender
php artisan migrate
php artisan recommender:make-feed posts
```

Choose the blog preset and answer the prompts to match your schema. For a non-interactive starting point use `php artisan recommender:make-feed posts --preset=blog -n`. Existing class files are skipped unless `--force` is supplied; an existing feed configuration is left unchanged. Review command output before continuing.

The package migration creates `rec_item_scores`, `rec_item_neighbours` and `rec_state`. The wizard publishes `config/recommender.php` if needed, registers `feeds.posts`, and creates:

- `app/Recommendation/Posts/PostInteractionSource.php`: reads your interactions. Check `TABLES`, `ITEM_COLUMN`, user and timestamp columns. Its history methods include every configured table, including views; edit them if only stronger actions should contribute to neighbours.
- `app/Recommendation/Posts/VisiblePostFilter.php`: selects allowed items. Match your publication and ownership rules.
- `app/Recommendation/Posts/PostCardTransformer.php`: returns shared card fields. Match your model and do not include viewer-specific data.

Add any extra event names to `events` in the feed configuration and return them from the interaction source. Inspect the generated queries and indexes before using them on large tables. See the [contracts reference](reference.md#contracts) and [examples](../examples/).

## 3. Build data and check it

Record some recent views or likes in your own tables, then run:

```bash
php artisan recommender:update-scores posts
php artisan recommender:build-neighbours posts
php artisan recommender:status posts
php artisan recommender:explain posts 123
```

Replace `123` with an actual user ID. The score command skips the newest minute to allow writes to settle and reads the last 30 days on its first run by default. `status` shows the watermark, score rows and neighbour rows. A watermark alone does not mean events were found.

Neighbours need overlapping user histories: by default an item pair must have at least two shared users. A small or new dataset may legitimately have no neighbours; trending can still work if scores exist. `explain` is a fresh diagnostic ranking, before exploration and diversity, and may differ from a cached page.

## 4. Serve a page

Add to `routes/web.php` or an appropriate API routes file:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Facades\Recommender;

Route::get('/posts/recommended', function (Request $request) {
    $feed = Recommender::page('posts', $request->user()?->id, (int) $request->query('page', 1));

    return response()->json($feed->toPaginatorArray(['path' => $request->url()]));
});
```

Visit `/posts/recommended`. The JSON contains `data`, `origin` (`personal` or `trending`) and `has_more`, along with paginator metadata. The exact cards depend on your transformer and data. A guest gets trending. An authenticated user's list can contain trending candidates even when its origin is `personal`.

Personal paging consumes a shared per-user seen set; page numbers are metadata rather than stable offsets. Repeat requests, concurrent clients and resets can affect results. Guests use offset paging over the shared trending list. See [reference](reference.md#serving-a-feed).

## 5. Keep it updated

In `routes/console.php` for Laravel 11–13:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('recommender:update-scores')->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('recommender:build-neighbours')->dailyAt('03:30')->withoutOverlapping(120)->onOneServer();
Schedule::command('recommender:prune')->dailyAt('04:30')->withoutOverlapping(60)->onOneServer();
```

Run Laravel's scheduler on your server. `onOneServer()` requires a shared cache with atomic locks across scheduler hosts. The commands above run synchronously when scheduled; if you use `Recommender::warm()`, also run a queue worker for the configured connection and queue (default queue `recommender`), for example `php artisan queue:work --queue=recommender`.

Use a shared cache with locks for multi-server deployments; `php artisan recommender:status` reports lock support. See [operations](operations.md) for cache, monitoring and historical data.

## Troubleshooting

| Symptom | Check |
|---|---|
| Feed is not configured | Inspect `feeds.posts`, command output and Laravel config cache. |
| SQL error | Match generated table, ID, foreign key and timestamp columns to your schema. |
| Empty `data` | Check recent eligible events, score rows, filter rules and card transformer; inspect application logs. A new event needs to be older than one minute before scoring. |
| Only trending or no neighbours | Check recent user histories, shared item pairs, `history_days` and `neighbours_min_co_count`; sparse data can be expected. |
| A changed item still shows old card data | Call `Recommender::forgetCard('posts', $id)` and consider invalidating relevant ranked lists; see [operations](operations.md). |

Next: [how ranking works](how-it-works.md) · [tuning](tuning.md) · [reference](reference.md).
