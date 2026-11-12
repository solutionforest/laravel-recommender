# Laravel Recommender

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![tests](https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml/badge.svg)](https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml)

Self-hosted “for you” feeds for Laravel. Reads likes/views you already store, learns in the background, serves pages from cache. No external recommendation API.

Laravel 10–13 · PHP 8.1+ · Redis recommended in production · runnable sample in [`examples/blog`](examples/blog)

---

## Start here

**New to the package?** Read this first (slow, plain language):

👉 **[docs/getting-started.md](docs/getting-started.md)**

Then, only when you need them:

| Doc | When |
|---|---|
| [Getting started](docs/getting-started.md) | First install — follow in order |
| [How it works](docs/how-it-works.md) | Big picture + request flow |
| [Tuning](docs/tuning.md) | Weights, recipes, real-feed lessons |
| [Reference](docs/reference.md) | Contracts, `page()` / paginator, jobs, config |
| [Operations](docs/operations.md) | Go-live, storage, locks, FAQ |
| [Maths](docs/maths.md) | CF, Wilson, half-life `hot` |

---

## Install

```bash
composer require solutionforest/laravel-recommender
php artisan migrate
php artisan vendor:publish --tag=recommender-config   # optional
```

Until Packagist is live, add a VCS repository:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/solutionforest/laravel-recommender" }
],
"require": {
  "solutionforest/laravel-recommender": "^0.1"
}
```

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

Schedule: scores ~every 10 min · neighbours nightly · prune daily (`withoutOverlapping`). Details: [getting started](docs/getting-started.md#step-4--schedule-the-kitchen).

---

## License

[MIT](LICENSE)
