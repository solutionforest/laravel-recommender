# Dashboard

One read-only page that answers “is the recommender healthy, and why does this user see that?”, in the same spirit as Laravel Horizon. It is plain Blade with no JavaScript build and no Livewire, and it refreshes itself every 10 seconds.

```text
https://your-app.test/recommender
```

![Dashboard overview](../art/screenshots/dashboard-healthy.png)

## What you see

| Area | Shows |
|---|---|
| Header | Kill switch state, cache store and whether it supports build locks, last update. Untick **live** to stop refreshing. |
| Feed tabs | One tab per feed in `config/recommender.php`; the dot is the feed's worst check (green / amber / red). |
| Cards | **Health** (OK / WARN / FAIL), **scores lag** since the last `update-scores`, **scored items**, **neighbours** (items with “similar items”). |
| Checks | Everything that can stop a feed from working, worst first, each with a fix. |
| Trending now | Top 10 items by time-decayed popularity, with positive (e.g. likes) and exposure (e.g. views) counts. |
| Explain a user | Enter a user id to see why each item is ranked: every source's contribution, best first. |

### Explain a user

![Explain a user](../art/screenshots/dashboard-explain.png)

`CoInteractionSource` means “people who liked what this user liked also liked this”, and `TrendingSource` means “popular right now”. A user who only gets `TrendingSource` rows has too little history or no shared interactions yet. Explain runs a fresh build before exploration and diversity, so it can differ slightly from the cached page. Live refresh pauses while a user is explained, so the build does not repeat.

### Checks

![Failing checks](../art/screenshots/dashboard-checks.png)

| Check | Fails / warns when |
|---|---|
| Model | `model` is not an Eloquent model, or its table does not exist |
| Interaction tables | a table is missing, or lacks its `id` / user / item / timestamp column |
| Event weights | an event in `interactions` has no weight in `events`; its rows would be ignored (warning) |
| Classes | a source, booster, filter, cards or groups class is missing or does not implement its contract |
| Cards | the feed has neither `model` nor `cards` |
| Scores | `update-scores` never ran, or is more than 1 hour behind (warning) |
| Neighbours | `build-neighbours` never ran, or produced an empty graph (warning) |
| Cache | the cache store cannot lock, so concurrent builds are last-write-wins (warning) |

Dark mode follows the operating system:

![Dark mode](../art/screenshots/dashboard-dark.png)

## Access

Like Horizon, the page is open in the `local` environment. Everywhere else only users allowed by the `viewRecommender` gate can open it; everyone else gets a 403.

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewRecommender', fn ($user) => in_array($user->email, [
        'ops@example.com',
    ]));
}
```

To require login first, add `auth` to the middleware (see below).

## Configuration

`config/recommender.php`:

```php
'dashboard' => [
    'enabled' => env('RECOMMENDER_DASHBOARD', true),
    'path' => env('RECOMMENDER_DASHBOARD_PATH', 'recommender'),
    'domain' => env('RECOMMENDER_DASHBOARD_DOMAIN'),
    'middleware' => ['web'],   // e.g. ['web', 'auth']; the gate is always applied
    'refresh' => 10,           // seconds between live updates, 0 = off
],
```

Set `RECOMMENDER_DASHBOARD=false` to remove the route entirely.

## Same checks in the terminal

```bash
php artisan recommender:doctor          # every feed
php artisan recommender:doctor posts    # one feed
```

It prints the same checks and exits with a non-zero code when any check fails, so you can run it in a deploy script or CI after migrations. `php artisan recommender:status` still prints the raw numbers.
