# Scaffold tutorial

Follow this page **in order**. About 15–30 minutes if your interaction tables already exist.

You will use `recommender:make-feed` — it creates the three classes + config.  
Deeper “why”: [getting-started.md](getting-started.md).

**Events are not only likes/views.** Scaffold defaults assume two tables (`*_likes`, `*_views`), but you can stream any types (`share`, `bookmark`, …) from one or more tables — see [README → event types](../README.md#1-event-types--weights-trending-hot).

---

## Before you start

You need:

- [ ] Laravel **11–13**, PHP **8.3+**
- [ ] Tables that store user↔item **interactions** (likes, views, shares, …), each with `user_id`, item id, `created_at`
- [ ] An Eloquent model for the item (e.g. `App\Models\Post`)

Example shape (names can differ — you will edit them):

| Table | Columns (minimum) |
|---|---|
| `posts` | `id`, `title`, `author_id`, `published`, `created_at` |
| `post_likes` | `user_id`, `post_id`, `created_at` |
| `post_views` | `user_id`, `post_id`, `created_at` |
| *(optional)* `post_shares` | `user_id`, `post_id`, `created_at` — third event type |

---

## Step 1 — Install

```bash
composer require solutionforest/laravel-recommender:^0.0.13
php artisan migrate
```

Check: tables `rec_item_scores`, `rec_item_neighbours`, `rec_state` exist.

---

## Step 2 — Scaffold

**Interactive (recommended)** — uses [Laravel Prompts](https://laravel.com/docs/prompts):

```bash
php artisan recommender:make-feed
```

Beautiful select / text / confirm prompts for preset (`blog` / `products` / `events` / `reviews` / `custom`), tables, columns, and an optional **third event** (purchase / join / share / …). Confirm the summary table before files are written.

**Non-interactive / CI** (`-n` skips prompts):

```bash
php artisan recommender:make-feed posts -n
php artisan recommender:make-feed products --preset=products -n
php artisan recommender:make-feed events --preset=events -n
php artisan recommender:make-feed posts --model=Post --likes=post_likes --views=post_views --item-column=post_id -n

# Third event via flags
php artisan recommender:make-feed products --purchases=product_purchases --purchase-event=purchase -n
```

This creates:

```text
app/Recommendation/Posts/
  PostInteractionSource.php
  VisiblePostFilter.php
  PostCardTransformer.php
config/recommender.php          # feed [posts] registered
```

---

## Step 3 — Edit the three files (5 minutes)

Open each file and match **your** schema. Defaults assume blog-style `post_*` / `author_id` / `published` / `title`.

### A. `PostInteractionSource`

- `TABLES` → your interaction table names (map each table → an **event string**, e.g. `like` / `view` / `share`)  
- `ITEM_COLUMN` → FK to the item (`post_id`, `comment_id`, …)  
- Add more tables/events as needed; register weights under `feeds.*.events` in config

### B. `VisiblePostFilter`

- Published column / rules  
- “Hide my own items” owner column (`author_id`, `user_id`, …)  
- Or delete the `when($context->userId, …)` line if you allow own items

### C. `PostCardTransformer`

- Fields in the card array (`title`, image URL, …)  
- Keep cards **viewer-agnostic** (no “liked by me” here)

---

## Step 4 — One route

```php
use SolutionForest\Recommender\Facades\Recommender;

Route::get('/posts/recommended', function (\Illuminate\Http\Request $request) {
    $result = Recommender::page(
        'posts',
        $request->user()?->id,
        (int) $request->query('page', 1),
    );

    return response()->json($result->toPaginatorArray([
        'path' => $request->url(),
    ]));
});
```

---

## Step 5 — Cook once (do not skip)

Empty kitchen = empty or “only trending” feed.

```bash
php artisan recommender:update-scores posts
php artisan recommender:build-neighbours posts
php artisan recommender:explain posts {aRealUserIdWhoHasLikes}
php artisan recommender:status posts
```

Checklist:

- [ ] `explain` shows more than only `TrendingSource` for an active user  
- [ ] `status` shows scores watermark + neighbour rows  
- [ ] `GET /posts/recommended` returns `data` + `origin`

---

## Step 6 — Schedule (production)

```php
// routes/console.php or Kernel
$schedule->command('recommender:update-scores')->everyTenMinutes()->withoutOverlapping()->onOneServer();
$schedule->command('recommender:build-neighbours')->dailyAt('03:30')->withoutOverlapping(120)->onOneServer();
$schedule->command('recommender:prune')->dailyAt('04:30')->withoutOverlapping(60)->onOneServer();
```

```bash
php artisan queue:work --queue=recommender
```

**Cache store** (lists + build locks) — pick one:

```env
# Recommended in production
CACHE_STORE=redis
# or only for this package:
RECOMMENDER_CACHE_STORE=redis

# Also OK: database (Laravel 11–13) after `php artisan cache:table`
# RECOMMENDER_CACHE_STORE=database
```

Confirm locks with `php artisan recommender:status`.

---

## Step 7 — Tune (optional)

Want shares to count more than likes? Extra event types? Sponsors? See [README → How to adjust the ratio](../README.md#how-to-adjust-the-ratio).

After weight changes:

```php
Recommender::forgetUser('posts', $userId);
Recommender::forgetTrending('posts');
```

Then re-run `explain`.

---

## Something wrong?

| Symptom | Fix |
|---|---|
| Unknown feed / empty feeds | Run `make-feed` again or check `config/recommender.php` |
| Empty JSON / only trending | Run `update-scores` + `build-neighbours`; check `explain` |
| SQL errors in InteractionSource | Table/column names in the generated class |
| Filter hides everything | `published` / owner rules too strict |
| Same cards for everyone | Freshness/trending weights too high — [tuning](tuning.md) |

---

## Done

You are set up. Next reads only when needed:

- [How it works](how-it-works.md)  
- [Tuning](tuning.md)  
- [Reference](reference.md)  
- Full sample: [`examples/blog`](../examples/blog)
