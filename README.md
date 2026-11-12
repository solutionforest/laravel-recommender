<p align="center">
  <img src="art/logo.svg" alt="Laravel Recommender" width="128" height="128">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-11%E2%80%9313-FF2D20?logo=laravel&logoColor=white" alt="Laravel 11–13">
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License: MIT"></a>
  <a href="https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml"><img src="https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml/badge.svg" alt="tests"></a>
  <a href="https://github.com/solutionforest/laravel-recommender/releases"><img src="https://img.shields.io/github/v/release/solutionforest/laravel-recommender?label=release" alt="release"></a>
</p>

# Laravel Recommender

**A simple recommendation engine for Laravel.** Self-hosted “for you” feeds — collaborative filtering, time-decayed trending, and explainable blends. Requires **PHP 8.3+** and **Laravel 11–13**.

If your product already stores user↔item interactions in **your** tables, this package turns them into a personalised ranked feed — without Amazon Personalize, a Python microservice, or shipping events to a third party.

**Not only likes and views.** Any event name works (`share`, `bookmark`, `comment`, `sponsor`, …). Defaults use `view` / `like` as examples; add a third (or tenth) type in config + your `InteractionSource`.

| You have… | This package gives you… |
|---|---|
| Interactions in **your** tables (like / view / share / …) | A recommendation engine: “acted together” + trending |
| A Laravel API or Livewire UI | `Recommender::page()` → cards / Laravel paginator JSON |
| Cache store + a queue worker | Fast cached lists, seen-set paging, explainable rankings |

Built for real product feeds (blog posts, inspiration / reviews, shops).

---

## Why use it?

- **Simple recommendation engine** — item–item CF + trending + your own rules; no ML ops stack.
- **Stays in your stack** — reads existing tables; only adds 3 small `rec_*` tables + cache keys.
- **Cheap on the request path** — scroll serves from cache; no `ORDER BY RAND()`, no external API call.
- **Explainable** — `recommender:explain` shows which sources scored each card.
- **Tunable** — weights, exploration, diversity caps — not a black-box model.
- **Runnable samples** — [`examples/`](examples/) (`blog`, `products` with 3 events, `reviews` + custom pet-type source), covered by tests.

---

## How we recommend

No neural net. A small, transparent recommendation engine: assemble a ranked list from a few clear signals, then serve it from cache.

```text
your interactions  →  background jobs  →  personal ranked list  →  next page of cards
   (any events)            (learn)              (in cache)            (no repeats)
```

1. **Learn in the background** (not on every scroll)
   - Every ~10 minutes: fold new interactions into a **trending “hot”** score (recent events count more; each event type has its own weight).
   - Nightly: build **neighbours** — “people who engaged with *this* also engaged with *that*” (item–item collaborative filtering).
2. **Build a personal list** when the user’s feed is missing or stale  
   Mix weighted **sources** (co-liked items, trending, plus any rules you add — e.g. same pet type, related shops), run your **filter** (published / not blocked), optional **boosters** (quality), sprinkle a little **exploration** (fresh items), then **diversity** caps (e.g. max 1 author per page).
3. **Serve pages from that list**  
   `Recommender::page()` returns the next unseen cards. Guests / brand-new users get a shared **trending** list until they have history.
4. **You can see why**  
   `php artisan recommender:explain {feed} {userId}` prints each card’s source scores.

Diagrams and request flow: [How it works](docs/how-it-works.md).  
Formulas behind “hot”, neighbours, and quality: [How we score (maths)](docs/maths.md).

---

## How to adjust the ratio

All knobs live in `config/recommender.php` under your feed (e.g. `feeds.posts`). **Bigger number = that signal counts more.** Weights are relative to each other.

### 1. Event types & weights (trending “hot”)

Event names are **arbitrary strings**. Scaffold defaults are `view` / `like`, but you can add more:

```php
'events' => [
    'view'     => 1.0,   // weak / browse
    'like'     => 4.0,   // stronger
    'share'    => 6.0,   // third type — any name you yield from InteractionSource
    // 'bookmark' => 5.0,
],
```

Only events listed here with weight `> 0` count toward trending. Default story is “a like ≈ 4 views”; raise `like` / add `share` if stronger actions should dominate.

These feed `recommender:update-scores` → the `hot` score. After changing them, re-run scores (and forget trending cache) so old `hot` values catch up.

Quality (Wilson) uses separate lists (also any of your event names):

```php
'positive_events' => ['like', 'share'],  // “good” actions
'exposure_events' => ['view'],           // “seen” / denominator
```

In `InteractionSource`, yield `new Interaction($userId, $itemId, 'share', $at)` (same pattern for every type).

### 2. Personal “liked together” vs trending vs your own rules

```php
'sources' => [
    CoInteractionSource::class => 0.6,  // people who liked X also liked Y
    TrendingSource::class => 0.2,       // hot items
    // YourSource::class => 0.3,        // e.g. same pet / related shop
],
'boosters' => [
    QualityBooster::class => 0.2,       // like-rate quality bump
],
```

Raise a source weight → that source’s cards rank higher in the blend.  
`exploration_ratio` (e.g. `0.15`) is different: it **reserves slots** for fresh/explore items, not the same as a source weight.

### 3. Sponsor wants certain posts / shops to show more

Two pieces (see [`examples/blog`](examples/blog)):

1. Put the author/shop ids in config  
2. Give them a **source** (inject into the pool) **and** a **booster** (push score up)

```php
'sponsored_author_ids' => [42, 99],   // or sponsored_shop_ids for shops

'sources' => [
    // …
    SponsoredShopSource::class => 0.20,   // raise → more sponsored candidates enter
],
'boosters' => [
    // …
    SponsoredShopBooster::class => 0.25,  // raise → those items rank higher
],
'diversity' => ['author' => 1],           // still cap so one sponsor cannot fill the whole page
```

Booster alone only helps items **already** in the pool — Source + Booster is the usual paid pattern.

### After you change weights

```php
Recommender::forgetUser('posts', $userId);
Recommender::forgetTrending('posts');
```

Then `recommender:explain posts {userId}` to verify. More recipes: [Tuning](docs/tuning.md).

---

## Start here

👉 **[docs/tutorial.md](docs/tutorial.md)** — step-by-step scaffold checklist (`make-feed` → edit → cook → schedule). Easiest path for a first install.

| Doc | When |
|---|---|
| [Scaffold tutorial](docs/tutorial.md) | First install — numbered checklist |
| [Getting started](docs/getting-started.md) | Same story, slower / more analogy |
| [How it works](docs/how-it-works.md) | Pipeline & request flow |
| [How we score (maths)](docs/maths.md) | Formulas for CF, trending, Wilson quality |
| [Tuning](docs/tuning.md) | Weights & recipes |
| [Reference](docs/reference.md) | API, jobs, config |
| [Operations](docs/operations.md) | Go-live, FAQ, scale |

---

## Install

```bash
composer require solutionforest/laravel-recommender:^0.0.11
php artisan migrate
php artisan recommender:make-feed
```

`recommender:make-feed` is an **interactive wizard** (presets: blog / products / reviews / custom, optional third event). Pass `-n` + flags for CI. It publishes `config/recommender.php` if needed, scaffolds the three classes under `app/Recommendation/...`, and registers the feed.

Full walkthrough: **[Scaffold tutorial](docs/tutorial.md)**. Or copy an example from [`examples/`](examples/) (`blog` · `products` · `reviews`).

On [Packagist](https://packagist.org/packages/solutionforest/laravel-recommender). Current: **`^0.0.11`** (PHP 8.3+ / Laravel 11–13).

Changelog: [CHANGELOG.md](CHANGELOG.md) · Releases: [GitHub Releases](https://github.com/solutionforest/laravel-recommender/releases)

---

## 60-second shape

```php
// 1–3. Wire InteractionSource + ItemFilter + CardTransformer (copy examples/blog)
// 4. config/recommender.php → feeds.posts → those classes + weights
// 5. Serve:
$result = Recommender::page('posts', $user?->id, (int) $request->query('page', 1));

return response()->json($result->toPaginatorArray(['path' => $request->url()]));
```

```bash
php artisan recommender:update-scores posts
php artisan recommender:build-neighbours posts
php artisan recommender:explain posts {userId}
php artisan recommender:status
```

Schedule: scores ~every 10 min · neighbours nightly · prune daily.  
Details: [getting started → schedule](docs/getting-started.md#step-4--schedule-the-kitchen).

**Cache / locks** (configurable via `RECOMMENDER_CACHE_STORE` or `CACHE_STORE`):

| Choice | When |
|---|---|
| **redis** | Production default — fastest hot path + atomic locks |
| **database** | OK on Laravel 11–13 if you ran `php artisan cache:table` (includes `cache_locks`) |
| **array** / **file** | Local / CI; fine for one process |

`php artisan recommender:status` shows whether locks are available. Details: [reference → Cache](docs/reference.md#cache-store--locks).

---

## For AI agents / Cursor

This package ships agent guidance so coding agents can wire feeds correctly in your app:

| File | Purpose |
|---|---|
| [`AGENTS.md`](AGENTS.md) | Short rules (package + consuming apps) |
| [`skills/laravel-recommender/SKILL.md`](skills/laravel-recommender/SKILL.md) | Cursor **Skill** — install into `.cursor/skills/` of the app |

```bash
mkdir -p .cursor/skills
cp -R vendor/solutionforest/laravel-recommender/skills/laravel-recommender .cursor/skills/
```

Then ask the agent to add a for-you / inspiration feed — it should use `recommender:make-feed`, not invent Personalize or `ORDER BY RAND()`.

---

## License

[MIT](LICENSE) © Solution Forest Limited
