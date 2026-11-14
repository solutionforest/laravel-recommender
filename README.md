<p align="center"><img src="art/logo.svg" alt="Laravel Recommender" width="120"></p>
<h1 align="center">Laravel Recommender</h1>
<p align="center"><strong>Build a “for you” feed from the interactions your Laravel app already records.</strong></p>
<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-10%E2%80%9313-FF2D20?logo=laravel&logoColor=white" alt="Laravel 10–13">
  <a href="https://phpstan.org/"><img src="https://img.shields.io/badge/PHPStan-level%2010-brightgreen.svg?logo=php&amp;logoColor=white" alt="PHPStan level 10"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License: MIT"></a>
  <a href="https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml"><img src="https://github.com/solutionforest/laravel-recommender/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/solutionforest/laravel-recommender/releases"><img src="https://img.shields.io/github/v/release/solutionforest/laravel-recommender?label=release" alt="Latest release"></a>
</p>

Your users view, like, or purchase things. This package reads those events from **your tables**, finds items people interacted with together, and combines that signal with trending items and your own rules. It runs in your Laravel application; you do not need a separate recommendation service or a background in mathematics.

```text
Your interactions → scheduled score updates → ranked items → JSON or Blade
```

**You provide:** item and interaction tables, visibility rules, and the fields to display. **The package provides:** ranking, cached lists, a shared trending feed, and tools to inspect results. Your app decides when to record interactions; the package also offers an explicit write helper. New or sparse data may produce trending results or an empty feed.

## How we recommend

Imagine a reader likes post A. If other readers interacted with both A and B, post B can become a suggestion. Popular recent posts can also appear, and you can add your own rules.

1. **Learn from activity:** scheduled commands update trending scores and find items that users interacted with together (called neighbours).
2. **Build a list:** sources suggest candidates; the package filters out disallowed items, combines their scores, and can apply optional boosts, randomness (`jitter`), exploration and diversity rules.
3. **Serve the feed:** the ranked list and cards are cached. Guests use a shared trending list; users with little history may see mostly trending items. If there are no eligible items, the feed can be empty.

Your app records the interactions (or lets `$post->recordInteraction($user, 'like')` write them with your feed's mapping) and controls visibility and card fields. Cards are shared across viewers, so add viewer-specific fields separately. The package stores derived scores, daily counters and neighbours in four `rec_*` tables. When ranked and trending items run out, feeds with a model can continue with the newest eligible items. See [how it works](docs/how-it-works.md) for the full flow and [operations](docs/operations.md#seen-memory-and-impressions) for memory and impression costs.

## Supported versions

| Laravel | PHP 8.3 | PHP 8.4 | PHP 8.5 | Laravel security fixes until | Supported here until | Notes |
|---|:---:|:---:|:---:|---|---|---|
| 13.x | ✅ | ✅ | ✅ | Q1 2028 | No end date | Also tested on MySQL 8 and with a Redis cache store |
| 12.x | ✅ | ✅ | ✅ | 24 Feb 2027 | First release after **31 Mar 2027** | Use 12.60 or newer for Laravel's own security fixes; plan an upgrade to 13 |
| 11.x | ✅ | ✅ | ✅ | **Ended** 12 Mar 2026 | First release after **31 Mar 2027** | Open advisories that will not be fixed (`composer audit`); upgrade to 12 or 13. On PHP 8.5, Laravel 11 itself prints deprecation notices |
| 10.x | ✅ | ✅ | ⚠️ | **Ended** 4 Feb 2025 | First release after **31 Mar 2027** | Best-effort. On PHP 8.5 CI only checks that the package installs and loads: Laravel 10's test tools do not run on PHP 8.5 |

✅ = the full test suite runs in [CI](.github/workflows/tests.yml) on every push (SQLite). "Laravel security fixes until" is Laravel's own [support policy](https://laravel.com/docs/releases#support-policy); "Supported here until" is when this package drops that version. PHP 8.2 and older are not supported. Other databases (PostgreSQL, MariaDB) and cache stores with locks should work but are not tested in CI.

## Your first feed

In an app with a `Post` model and interaction tables (`post_likes`, `post_views` with `user_id`, `post_id`, `created_at`):

**1. Install and set up**

```bash
composer require solutionforest/laravel-recommender
php artisan recommender:bootstrap posts
```

`bootstrap` migrates, asks for your tables, writes the feed, builds the first scores and checks everything:

![recommender:bootstrap](art/screenshots/terminal-bootstrap.png)

**2. Tell the model what may be shown**

```php
use SolutionForest\Recommender\Eloquent\Recommendable;

class Post extends Model
{
    use Recommendable;

    public function scopeRecommendable($query, $context)    // optional: what may be shown
    {
        $query->where('published', true);
    }

    public function toRecommendationCard(): array           // optional: the JSON per item
    {
        return ['id' => $this->id, 'title' => $this->title];
    }
}
```

**3. Add a route**

```php
Route::get('/posts/for-you', fn (Request $request) => Post::recommendedFor($request->user()));
```

That's it. The response is Laravel's paginator JSON (with `next_cursor` for infinite scroll), and the scoring jobs are scheduled for you — just keep Laravel's scheduler running. The whole feed `bootstrap` wrote is a few lines of config:

```php
'posts' => [
    'preset' => 'social',   // catalog | social | events
    'model' => App\Models\Post::class,
    'interactions' => ['post_likes' => 'like', 'post_views' => 'view'],
],
```

For a related-posts block, `$post->similar(6)` returns the cards of the posts most often liked together with this one. See [similar items](docs/reference.md#similar-items-related-posts).

**[Follow the first-feed tutorial →](docs/tutorial.md)** for other table shapes, custom classes and troubleshooting.

## Choose your next step

| I want to… | Read |
|---|---|
| Understand the terms before starting | [Getting started](docs/getting-started.md) |
| Follow a complete working setup | [First-feed tutorial](docs/tutorial.md) |
| Understand ranking and cache behavior | [How it works](docs/how-it-works.md) |
| Change what influences the feed | [Tuning](docs/tuning.md) |
| Add my own rules, several feeds, A/B tests, tests | [Advanced usage](docs/advanced.md) |
| Look up methods, contracts and configuration | [Reference](docs/reference.md) |
| Monitor feeds in the browser | [Dashboard](docs/dashboard.md) |
| Run, monitor or rebuild in production | [Operations](docs/operations.md) |
| See measured speed, load and concurrency results | [Performance](docs/performance.md) |
| Explore the formulas | [Maths](docs/maths.md) (optional) |
| Adapt an integration | [Examples](examples/) — blog, products, events and reviews |

## Dashboard

Open `/recommender` to see each feed's health, what is broken and how to fix it, what is trending, and why a given user gets their recommendations.

![Recommender dashboard](art/screenshots/dashboard-healthy.png)

It is open in the `local` environment. In production, choose who can see it:

```php
Gate::define('viewRecommender', fn ($user) => $user->is_admin);
```

`php artisan recommender:doctor` runs the same checks in the terminal and fails a deploy when something is wrong:

![recommender:doctor](art/screenshots/terminal-doctor.png)

**[Dashboard guide →](docs/dashboard.md)**

## Why does a user see this?

Use *Explain a user* on the dashboard (or `php artisan recommender:explain posts 123`): every item's score split by source, plus where it lands after exploration and diversity. `Recommender::stats('posts', $post)` tells you how good one item is. Rebuilds, pruning and scale are in [operations](docs/operations.md); weights in [tuning](docs/tuning.md).

## For coding agents

Package guidance is available in [AGENTS.md](AGENTS.md) and the [Laravel Recommender skill](skills/laravel-recommender/SKILL.md). Copy the skill into a consuming application's `.cursor/skills/` if desired.

[Changelog](CHANGELOG.md) · [Releases](https://github.com/solutionforest/laravel-recommender/releases) · [Publishing a release](docs/operations.md#publishing-a-package-release) · [MIT license](LICENSE)
