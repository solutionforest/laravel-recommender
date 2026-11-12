<p align="center">
  <img src="art/logo.svg" alt="Laravel Recommender" width="128" height="128">
</p>

# Laravel Recommender

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![tests](https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml/badge.svg)](https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml)
[![Latest tag](https://img.shields.io/github/v/tag/solutionforest/laravel-recommender?label=version)](https://github.com/solutionforest/laravel-recommender/tags)

**Self-hosted “for you” feeds for Laravel apps.**

If your product already stores likes, views, or similar interactions, this package turns them into a personalised ranked feed — without Amazon Personalize, a Python microservice, or shipping user events to a third party.

| You have… | This package gives you… |
|---|---|
| Likes / views in **your** tables | Background jobs that learn “liked together” + trending |
| A Laravel API or Livewire UI | `Recommender::page()` → cards / Laravel paginator JSON |
| Redis (prod) + a queue worker | Fast cached lists, seen-set paging, explainable rankings |

Built for real product feeds (blog posts, inspiration / reviews, shops). Laravel **10–13**, PHP **8.1+**.

---

## Why use it?

- **Stays in your stack** — reads existing tables; only adds 3 small `rec_*` tables + cache keys.
- **Cheap on the request path** — scroll serves from cache; no `ORDER BY RAND()`, no external API call.
- **Explainable** — `recommender:explain` shows which sources scored each card (co-interaction, trending, your rules).
- **Tunable** — weights, exploration, diversity caps — not a black-box model.
- **Runnable sample** — [`examples/blog`](examples/blog) (~200 lines), covered by tests.

Not a deep-learning ranker. Classic item–item CF + time-decayed trending + your filters/boosters. Often enough for sparse first-party like/view data.

---

## Start here

👉 **[docs/getting-started.md](docs/getting-started.md)** — slow, plain walkthrough (3 classes → cook once → schedule).

| Doc | When |
|---|---|
| [Getting started](docs/getting-started.md) | First install |
| [How it works](docs/how-it-works.md) | Big picture |
| [Tuning](docs/tuning.md) | Weights & recipes |
| [Reference](docs/reference.md) | API, jobs, config |
| [Operations](docs/operations.md) | Go-live, FAQ, scale |
| [Maths](docs/maths.md) | Formulas |

---

## Install

```bash
composer require solutionforest/laravel-recommender:^0.0.1
php artisan migrate
php artisan vendor:publish --tag=recommender-config   # optional
```

Until Packagist is live, add the VCS repo:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/solutionforest/laravel-recommender" }
],
"require": {
  "solutionforest/laravel-recommender": "^0.0.1"
}
```

Current release: **v0.0.1**.

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
