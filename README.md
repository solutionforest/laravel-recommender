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

**Self-hosted “for you” feeds for Laravel apps.** Requires **PHP 8.3+** and **Laravel 11–13**.

If your product already stores likes, views, or similar interactions, this package turns them into a personalised ranked feed — without Amazon Personalize, a Python microservice, or shipping user events to a third party.

| You have… | This package gives you… |
|---|---|
| Likes / views in **your** tables | Background jobs that learn “liked together” + trending |
| A Laravel API or Livewire UI | `Recommender::page()` → cards / Laravel paginator JSON |
| Redis (prod) + a queue worker | Fast cached lists, seen-set paging, explainable rankings |

Built for real product feeds (blog posts, inspiration / reviews, shops).

---

## Why use it?

- **Stays in your stack** — reads existing tables; only adds 3 small `rec_*` tables + cache keys.
- **Cheap on the request path** — scroll serves from cache; no `ORDER BY RAND()`, no external API call.
- **Explainable** — `recommender:explain` shows which sources scored each card (co-interaction, trending, your rules).
- **Tunable** — weights, exploration, diversity caps — not a black-box model.
- **Runnable sample** — [`examples/blog`](examples/blog) (~200 lines), covered by tests.

---

## How we recommend

No neural net. We assemble a ranked list from a few clear signals, then serve it from cache.

```text
your likes/views  →  background jobs  →  personal ranked list  →  next page of cards
                         (learn)              (in Redis)            (no repeats)
```

1. **Learn in the background** (not on every scroll)
   - Every ~10 minutes: fold new likes/views into a **trending “hot”** score (recent events count more).
   - Nightly: build **neighbours** — “people who liked *this* also liked *that*” (item–item collaborative filtering).
2. **Build a personal list** when the user’s feed is missing or stale  
   Mix weighted **sources** (co-liked items, trending, plus any rules you add — e.g. same pet type, related shops), run your **filter** (published / not blocked), optional **boosters** (quality), sprinkle a little **exploration** (fresh items), then **diversity** caps (e.g. max 1 author per page).
3. **Serve pages from that list**  
   `Recommender::page()` returns the next unseen cards. Guests / brand-new users get a shared **trending** list until they have history.
4. **You can see why**  
   `php artisan recommender:explain {feed} {userId}` prints each card’s source scores.

Diagrams and request flow: [How it works](docs/how-it-works.md).  
Formulas behind “hot”, neighbours, and quality: [How we score (maths)](docs/maths.md).

---

## Start here

👉 **[docs/getting-started.md](docs/getting-started.md)** — slow, plain walkthrough (3 classes → cook once → schedule).

| Doc | When |
|---|---|
| [Getting started](docs/getting-started.md) | First install |
| [How it works](docs/how-it-works.md) | Pipeline & request flow |
| [How we score (maths)](docs/maths.md) | Formulas for CF, trending, Wilson quality |
| [Tuning](docs/tuning.md) | Weights & recipes |
| [Reference](docs/reference.md) | API, jobs, config |
| [Operations](docs/operations.md) | Go-live, FAQ, scale |

---

## Install

```bash
composer require solutionforest/laravel-recommender:^0.0.2
php artisan migrate
php artisan vendor:publish --tag=recommender-config   # optional
```

On [Packagist](https://packagist.org/packages/solutionforest/laravel-recommender). Prefer **`^0.0.2`** (PHP 8.3+ / Laravel 11–13). Ignore the obsolete `v0.1.0` tag.

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

Production: **Redis** for cache locks. Local lab: `array` / `file` / `database` is fine.

---

## License

[MIT](LICENSE) © Solution Forest Limited
