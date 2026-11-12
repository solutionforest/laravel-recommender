<p align="center"><img src="art/logo.svg" alt="Laravel Recommender" width="120"></p>
<h1 align="center">Laravel Recommender</h1>
<p align="center"><strong>Build a “for you” feed from the interactions your Laravel app already records.</strong></p>
<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-11%E2%80%9313-FF2D20?logo=laravel&logoColor=white" alt="Laravel 11–13">
  <a href="https://phpstan.org/"><img src="https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?logo=php&amp;logoColor=white" alt="PHPStan level 8"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License: MIT"></a>
  <a href="https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml"><img src="https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/solutionforest/laravel-recommender/releases"><img src="https://img.shields.io/github/v/release/solutionforest/laravel-recommender?label=release" alt="Latest release"></a>
</p>

Your users view, like, or purchase things. This package reads those events from **your tables**, finds items people interacted with together, and combines that signal with trending items and your own rules. It runs in your Laravel application; you do not need a separate recommendation service or a background in mathematics.

```text
Your interactions → scheduled score updates → ranked items → JSON or Blade
```

**You provide:** item and interaction tables, visibility rules, and the fields to display. **The package provides:** ranking, cached lists, a shared trending feed, and tools to inspect results. It does not record interactions for you. New or sparse data may produce trending results or an empty feed.

## How we recommend

Imagine a reader likes post A. If other readers interacted with both A and B, post B can become a suggestion. Popular recent posts can also appear, and you can add your own rules.

1. **Learn from activity:** scheduled commands update trending scores and find items that users interacted with together (called neighbours).
2. **Build a list:** sources suggest candidates; the package filters out disallowed items, combines their scores, and can apply optional boosts, exploration and diversity rules.
3. **Serve the feed:** the ranked list and cards are cached. Guests use a shared trending list; users with little history may see mostly trending items. If there are no eligible items, the feed can be empty.

Your app records the interactions and controls visibility and card fields. Cards are shared across viewers, so add viewer-specific fields separately. The package stores derived scores and neighbours in three `rec_*` tables. Personal paging uses a bounded seen set, so repeats can occur after expiry or resets. See [how it works](docs/how-it-works.md) for the full flow and [operations](docs/operations.md) for cache and production details.

## Your first feed

Requires PHP 8.3+ and Laravel 11, 12, or 13. In an existing Laravel app with a `Post` model and interaction tables:

```bash
composer require solutionforest/laravel-recommender
php artisan migrate
php artisan recommender:make-feed posts
```

The wizard creates three classes and registers a feed. **Edit the generated classes to match your schema**.

Or skip the classes. If each interaction table has `user_id`, `post_id` and `created_at`, this is the whole feed:

```php
// config/recommender.php
'feeds' => [
    'posts' => [
        'model' => App\Models\Post::class,
        'interactions' => ['post_likes' => 'like', 'post_views' => 'view'],
    ],
],
```

Add an optional `scopeRecommendable()` and `toRecommendationCard()` to the model for visibility and the JSON shape. See [`examples/simple`](examples/simple/).

Then build scores and neighbours:

```bash
php artisan recommender:update-scores posts
php artisan recommender:build-neighbours posts
php artisan recommender:status posts
```

Expose the feed from a route:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/posts/recommended', fn (Request $request) => recommendations('posts', $request->user()));
```

The response is Laravel's paginator JSON plus `origin` and `has_more`; `?page=` is read automatically. `Recommender::page('posts', $user)` does the same.

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

## Inspect and tune

Open `/recommender` for a Horizon-style dashboard (health, checks, trending, explain). It is open locally; elsewhere `Gate::define('viewRecommender', …)`. `php artisan recommender:doctor` runs the same checks in the terminal.

```bash
php artisan recommender:explain posts 123
```

Replace `123` with a real user ID. This shows source and booster scores for a fresh build before exploration and diversity; it is a diagnostic, not a guarantee of the exact cached page. If the user has little history or few shared interactions, trending may be the only useful signal. See [tuning](docs/tuning.md) before changing weights.

## For coding agents

Package guidance is available in [AGENTS.md](AGENTS.md) and the [Laravel Recommender skill](skills/laravel-recommender/SKILL.md). Copy the skill into a consuming application's `.cursor/skills/` if desired.

[Changelog](CHANGELOG.md) · [Releases](https://github.com/solutionforest/laravel-recommender/releases) · [Publishing a release](docs/operations.md#publishing-a-package-release) · [MIT license](LICENSE)
