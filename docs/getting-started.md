# Getting started (slow and clear)

Want a numbered checklist instead? Use **[tutorial.md](tutorial.md)** (`make-feed` → edit → cook).

Welcome. You do **not** need to understand maths or Redis on day one.

This page teaches one idea at a time. When you finish, you will have a working “for you” feed.

If something fails, stop and check the [“Something wrong?”](#something-wrong) box before you change more code.

---

## The one sentence

Your app already records user↔item interactions (likes, views, shares, bookmarks — **any** event names you choose).
This package **reads** those rows in the background, learns “people who engaged with this also engaged with that”, and serves each user a **personal list of cards** from cache.

Scaffold examples talk about `like` / `view` because that is common — you are not limited to two types.

You do **not** send data to an external AI service. Everything stays in your Laravel app.

---

## Picture it like school lunch

| School | This package |
|---|---|
| Kids tell the kitchen what they like | Your interaction tables (likes / views / shares / …) |
| Kitchen cooks once, not every time someone asks | Background jobs (`update-scores`, `build-neighbours`) |
| Each child gets a tray with today’s picks | Cached ranked id list per user |
| Teacher hands out the next 10 dishes | `Recommender::page()` → JSON / Blade |

Scroll = “please give me the next tray.”  
Nobody re-cooks the whole kitchen on every scroll.

---

## What you must write (only 3 classes)

Think of three homework sheets. Fastest: `php artisan recommender:make-feed posts` (or copy [`examples/blog`](../examples/blog)).

### ① InteractionSource — “where are the interactions?”

Tell the package how to **stream** events from **your** tables (each row → an event name + weight in config).

- Jobs call this. The hot feed path does not.
- Use `lazyById` / chunks. Do not `->get()` a million rows into memory.

### ② ItemFilter — “what is allowed on screen?”

Given a list of ids, return the ones that may show (published, not blocked, not my own post…).

- **One query** for the whole list (`whereIn`), not one query per id.

### ③ CardTransformer — “what does the API return?”

Given ids, return the card arrays (`id`, `title`, image…).

- Cards are **cached and shared**. Do not put “liked by me” inside the card. Add that in the controller if you need it.

That is the whole integration. Everything else is config + schedule.

### Optional later — custom sources and `Context`

When you add a product rule (`CandidateSource` / `Booster`), the first argument is always a **`Context`**:

```php
use SolutionForest\Recommender\Support\Context;

public function candidates(Context $context, int $limit): array
{
    if (! $context->userId) {
        return []; // no logged-in viewer → skip personal rules
    }

    // $context->userId  → from Recommender::page('posts', $userId, …)
    // $context->now()   → “current time” for date filters
    // $context->feed    → this feed’s config
}
```

`Context` is **not** the HTTP request. Full table: [reference.md → What is Context?](reference.md#what-is-context).

---

## Do this in order (do not skip)

### Step 0 — Install

```bash
composer require solutionforest/laravel-recommender:^0.0.14
php artisan migrate
php artisan recommender:make-feed posts
```

You should now have three tables: `rec_item_scores`, `rec_item_neighbours`, `rec_state`.  
Those are the package’s notebooks. Your likes table stays yours.

`make-feed` also publishes `config/recommender.php` (if missing) and registers the feed.

### Step 1 — Adjust the generated classes

Generated under `app/Recommendation/Posts/` (for feed `posts`):

1. `PostInteractionSource` — set interaction table names (+ event strings)  
2. `VisiblePostFilter` — published / not-mine rules  
3. `PostCardTransformer` — card JSON fields  

Or copy [`examples/blog`](../examples/blog) by hand if you prefer.

### Step 2 — Serve one route

```php
use SolutionForest\Recommender\Facades\Recommender;

$result = Recommender::page('posts', $request->user()?->id, (int) $request->query('page', 1));

return response()->json($result->toPaginatorArray(['path' => $request->url()]));
```

`page()` returns a `FeedPage`.  
`toPaginatorArray()` makes Laravel’s normal paginator JSON (links, `data`, …) plus `origin` and `has_more`.

### Step 3 — Cook once (very important!)

Empty kitchen = empty or “only trending” feed. Run **once** after wiring:

```bash
php artisan recommender:update-scores posts
php artisan recommender:build-neighbours posts
php artisan recommender:explain posts {aRealUserId}
```

`explain` should show more than only `TrendingSource` for an active user.  
If you only see trending → neighbours or history are empty. Fix that before you tweak weights.

### Step 4 — Schedule the kitchen

```php
$schedule->command('recommender:update-scores')->everyTenMinutes()->withoutOverlapping()->onOneServer();
$schedule->command('recommender:build-neighbours')->dailyAt('03:30')->withoutOverlapping(120)->onOneServer();
$schedule->command('recommender:prune')->dailyAt('04:30')->withoutOverlapping(60)->onOneServer();
```

| How often | Command | Plain English |
|---|---|---|
| ~10 min | `update-scores` | Fold new interactions into “hot” (per event weight) |
| Nightly | `build-neighbours` | Rebuild “engaged together” graph |
| Daily | `prune` | Throw away idle score rows |

Cache: set `RECOMMENDER_CACHE_STORE` (or `CACHE_STORE`) to `redis` (prod), `database` (with `cache_locks`), or `array`/`file` (local).

```bash
php artisan queue:work --queue=recommender
```

---

## Tiny checklist before you show anyone

- [ ] `migrate` done  
- [ ] 3 classes wired in `config/recommender.php`  
- [ ] `update-scores` ran  
- [ ] `build-neighbours` ran  
- [ ] `explain` looks personal for one real user  
- [ ] Schedule + queue worker in place  
- [ ] Cache store with locks in production (`redis` or `database` + `cache_locks`) — check `recommender:status`  

---

## Something wrong?

| What you see | What to try |
|---|---|
| Empty feed / only trending | Run scores + neighbours again; check `explain` |
| `CoInteractionSource` missing in explain | Neighbours empty — rebuild; check user’s recent items exist in `rec_item_neighbours` |
| Everyone sees the same cards | Freshness / trending weights too high — see [tuning](tuning.md) |
| Imported years of old data, still tiny | Widen `scores_backfill_days` or reset watermark — see [operations](operations.md) |
| Cache lock / weird rebuild races | Use a LockProvider store (`redis` or `database` + `cache_locks`); check `recommender:status` |

Health snapshot:

```bash
php artisan recommender:status
php artisan recommender:status posts
```

---

## After it works

1. Read [how it works](how-it-works.md) (still plain language).  
2. Change **one** weight at a time — [tuning](tuning.md).  
3. Need infinite scroll until the catalog ends? Copy [`InfiniteScrollFeed`](../examples/blog/src/InfiniteScrollFeed.php).  
4. How scoring works (formulas): [How we score (maths)](maths.md).  
5. Full knobs: [reference](reference.md).

You are done with “day one.” Go try the feed. Come back to the other docs when you are curious, not scared.
