# Create your first feed

This walkthrough uses a `posts` feed in an existing Laravel application. Replace its table and column names with yours. You need PHP 8.3+, Laravel 10–13 ([supported versions](../README.md#supported-versions)), a working database and an item model such as `App\Models\Post`.

## 1. Check your data

For the generated blog scaffold, these are example fields (not tables the package creates):

| Table | Fields used by the generated classes |
|---|---|
| `posts` | `id`, `title`, `published`, `author_id`, `created_at` |
| `post_likes`, `post_views` | incrementing `id`, `user_id`, `post_id`, `created_at` |

One table per interaction type, each with a user id, an item id, a timestamp and an incrementing `id`. Ids can be integers, UUIDs or ULIDs. A single events table, or very different columns, needs a small `InteractionSource` class instead (option B below). You are responsible for recording events and enforcing your app's visibility and authorization rules.

## 2. Install and configure

```bash
composer require solutionforest/laravel-recommender
php artisan recommender:bootstrap posts      # everything below in one go: migrate, register, build, check
```

Or step by step:

```bash
php artisan vendor:publish --tag=recommender-config
php artisan migrate
```

The migrations create `rec_item_scores`, `rec_item_daily`, `rec_item_neighbours` and `rec_state`.

### Option A — config only (recommended)

Add the feed to `config/recommender.php`:

```php
'feeds' => [
    'posts' => [
        'preset' => 'social',   // optional: catalog | social | events
        'model' => App\Models\Post::class,
        'interactions' => [
            'post_likes' => 'like',
            'post_views' => 'view',
        ],
    ],
],
```

A **preset** sets sources, boosters, event weights, decay and exploration for a kind of product; anything you add to the feed overrides it. See [presets](reference.md#presets).

The item column defaults to `{model}_id` (`post_id`); set `item_column`, `user_column` or `timestamp_column` if yours differ, or override per table: `'bookmarks' => ['event' => 'like', 'item' => 'bookmarkable_id']`.

`php artisan recommender:make-feed posts` writes exactly this for you (it asks for the tables and columns) and prints the model methods below.

Then tell the package which posts may be shown and what to return for each, on the model:

```php
use Illuminate\Database\Eloquent\Builder;
use SolutionForest\Recommender\Eloquent\Recommendable;
use SolutionForest\Recommender\Support\Context;

class Post extends Model
{
    use Recommendable; // refresh cached cards on save / delete

    public function scopeRecommendable(Builder $query, Context $context): void
    {
        $query->where('published', true)
            ->when($context->userId, fn ($q, $id) => $q->where('author_id', '<>', $id));
    }

    public function toRecommendationCard(): array
    {
        return ['id' => $this->id, 'title' => $this->title];
    }
}
```

Both methods are optional: without the scope every existing post may be shown, and without `toRecommendationCard()` the card is `toArray()`. Cards are cached and shared by every viewer, so keep viewer-specific fields out. A complete example is in [`examples/simple`](../examples/simple/).

Check the setup before building anything:

```bash
php artisan recommender:doctor posts
```

It lists missing tables or columns, events without a weight and classes that do not exist, each with a fix. Failures before the first `update-scores` / `build-neighbours` run are expected.

### Option B — scaffold classes

When your schema needs custom queries, generate classes instead:

```bash
php artisan recommender:make-feed posts --classes
```

Choose the blog preset and answer the prompts to match your schema. For a non-interactive starting point use `php artisan recommender:make-feed posts --classes --preset=blog -n`. Existing class files are skipped unless `--force` is supplied; an existing feed configuration is left unchanged. Review command output before continuing.

The wizard publishes `config/recommender.php` if needed, registers `feeds.posts`, and creates:

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

Neighbours need overlapping user histories: by default an item pair must have at least two shared users. A small or new dataset may legitimately have no neighbours; trending can still work if scores exist. `explain` is a fresh ranking; its *final* and *cached* columns show where each item lands after exploration and diversity and in the list the user is served now.

## 4. Serve a page

Add to `routes/web.php` or an appropriate API routes file:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/posts/recommended', fn (Request $request) => Post::recommendedFor($request->user()));
// or, without the trait: recommendations('posts', $request->user())
```

Visit `/posts/recommended`. The JSON is Laravel's simple-paginator shape for a signed-in user (`data`, `current_page`, `next_page_url`, …; guests get the full paginator with `total` and `links`) plus `origin` (`personal`, `trending` or `tail`), `has_more` and `next_cursor`; `?page=` is read automatically. `Recommender::page('posts', $request->user())` does the same and returns a `FeedPage` you can also turn into `toPaginator()` for Blade. The exact cards depend on your transformer and data. A guest gets trending. An authenticated user's list can contain trending candidates even when its origin is `personal`.

On a post's own page, `$post->similar(4)` returns the cards of the posts most often liked together with it: the same for every viewer, cached per post, and empty until `build-neighbours` has found neighbours for it. See [reference → similar items](reference.md#similar-items-related-posts).

A signed-in feed is sequential: each request serves the next unseen batch, so a refresh advances it and page numbers are not stable offsets — follow `next_cursor` to continue. Repeat requests, concurrent clients and resets can affect results. Guests use offset paging over the shared trending list. See [reference](reference.md#serving-a-feed).

## 5. Keep it updated

Nothing to add: the package schedules `update-scores` (every 10 minutes), `build-neighbours` (nightly) and `prune` (daily) itself, with `withoutOverlapping()->onOneServer()`. Just run Laravel's scheduler on your server (`php artisan schedule:work`, or the usual cron entry). To change the times or schedule them yourself, see [reference → background jobs](reference.md#background-jobs).

Run Laravel's scheduler on your server. `onOneServer()` requires a shared cache with atomic locks across scheduler hosts. The commands above run synchronously when scheduled; if you use `Recommender::warm()`, also run a queue worker for the configured connection and queue (default queue `recommender`), for example `php artisan queue:work --queue=recommender`.

Use a shared cache with locks for multi-server deployments; `php artisan recommender:status` reports lock support. See [operations](operations.md) for cache, monitoring and historical data.

## 6. Watch it

Open `/recommender` for the [dashboard](dashboard.md): health, checks, trending items and *Explain a user*. It is open in the `local` environment; in production allow your team with a gate:

```php
Gate::define('viewRecommender', fn ($user) => $user->is_admin);
```

## Troubleshooting

| Symptom | Check |
|---|---|
| Not sure what is wrong | Run `php artisan recommender:doctor posts` or open `/recommender`. |
| Feed is not configured | Inspect `feeds.posts`, command output and Laravel config cache. |
| SQL error | Match generated table, ID, foreign key and timestamp columns to your schema. |
| Empty `data` | Check recent eligible events, score rows, filter rules and card transformer; inspect application logs. A new event needs to be older than one minute before scoring. |
| Only trending or no neighbours | Check recent user histories, shared item pairs, `history_days` and `neighbours_min_co_count`; sparse data can be expected. |
| A changed item still shows old card data | Add `use Recommendable;` to the model, or call `Recommender::forgetCard('posts', $id)`; consider invalidating relevant ranked lists, see [operations](operations.md). |

Next: [dashboard](dashboard.md) · [how ranking works](how-it-works.md) · [tuning](tuning.md) · [reference](reference.md).
