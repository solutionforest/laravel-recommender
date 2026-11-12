<p align="center"><img src="art/logo.svg" alt="Laravel Recommender" width="120"></p>
<h1 align="center">Laravel Recommender</h1>
<p align="center"><strong>Build a “for you” feed from the interactions your Laravel app already records.</strong></p>
<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-11%E2%80%9313-FF2D20?logo=laravel&logoColor=white" alt="Laravel 11–13">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue" alt="MIT license"></a>
  <a href="https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml"><img src="https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
</p>

Your users view, like, or purchase things. This package reads those events from **your tables**, finds items people interacted with together, and combines that signal with trending items and your own rules. It runs in your Laravel application; you do not need a separate recommendation service or a background in mathematics.

```text
Your interactions → scheduled score updates → ranked items → JSON or Blade
```

**You provide:** item and interaction tables, visibility rules, and the fields to display. **The package provides:** ranking, cached lists, a shared trending feed, and tools to inspect results. It does not record interactions for you. New or sparse data may produce trending results or an empty feed.

## Your first feed

Requires PHP 8.3+ and Laravel 11, 12, or 13. In an existing Laravel app with a `Post` model and interaction tables:

```bash
composer require solutionforest/laravel-recommender
php artisan migrate
php artisan recommender:make-feed posts
```

The wizard creates three classes and registers a feed. **Edit the generated classes to match your schema**, then build scores and neighbours:

```bash
php artisan recommender:update-scores posts
php artisan recommender:build-neighbours posts
php artisan recommender:status posts
```

Expose the feed from a route:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Facades\Recommender;

Route::get('/posts/recommended', function (Request $request) {
    $feed = Recommender::page('posts', $request->user()?->id, (int) $request->query('page', 1));

    return response()->json($feed->toPaginatorArray(['path' => $request->url()]));
});
```

**[Follow the first-feed tutorial →](docs/tutorial.md)** for the required table shape, checks, scheduler and troubleshooting. Run the maintenance commands after your app has recorded interactions; the first score run excludes the most recent minute.

## Choose your next step

| I want to… | Read |
|---|---|
| Understand the terms before starting | [Getting started](docs/getting-started.md) |
| Follow a complete working setup | [First-feed tutorial](docs/tutorial.md) |
| Understand ranking and cache behavior | [How it works](docs/how-it-works.md) |
| Change what influences the feed | [Tuning](docs/tuning.md) |
| Look up methods, contracts and configuration | [Reference](docs/reference.md) |
| Run, monitor or rebuild in production | [Operations](docs/operations.md) |
| Explore the formulas | [Maths](docs/maths.md) (optional) |
| Adapt an integration | [Examples](examples/) — blog, products, events and reviews |
| Review proposed usability improvements | [Improvement proposals](docs/improvements.md) |

## How it fits together

- **Scores** measure recent activity; the scheduled `update-scores` command reads your events.
- **Neighbours** connect items that users interacted with together; `build-neighbours` rebuilds them.
- **Sources** supply possible items. A weighted blend combines their scores; optional boosters, exploration and diversity adjust the list.
- **Filters and cards** are yours: decide what may appear and what each item looks like. Cards are cached across viewers, so add viewer-specific fields separately.

The package stores derived data in three `rec_*` tables and caches lists, seen IDs and cards. Personal paging uses a bounded seen set rather than stable page offsets; guest pages use offsets. Cache expiry, resets and exhausted lists can cause repeats. Read [operations](docs/operations.md) before production.

## Inspect and tune

```bash
php artisan recommender:explain posts 123
```

Replace `123` with a real user ID. This shows source and booster scores for a fresh build before exploration and diversity; it is a diagnostic, not a guarantee of the exact cached page. If the user has little history or few shared interactions, trending may be the only useful signal. See [tuning](docs/tuning.md) before changing weights.

## For coding agents

Package guidance is available in [AGENTS.md](AGENTS.md) and the [Laravel Recommender skill](skills/laravel-recommender/SKILL.md). Copy the skill into a consuming application's `.cursor/skills/` if desired.

[Changelog](CHANGELOG.md) · [Releases](https://github.com/solutionforest/laravel-recommender/releases) · [MIT license](LICENSE)
