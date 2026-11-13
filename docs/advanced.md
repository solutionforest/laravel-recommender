# Advanced usage

Most apps never need this page: a feed is a `model`, an `interactions` map and an optional `preset` ([tutorial](tutorial.md)). Reach for the sections below when your product has its own rules.

- [Your own candidate source](#your-own-candidate-source)
- [Your own booster](#your-own-booster)
- [Passing extra context](#passing-extra-context)
- [Replacing a built-in piece with a class](#replacing-a-built-in-piece-with-a-class)
- [Interaction tables that do not fit the defaults](#interaction-tables-that-do-not-fit-the-defaults)
- [Several feeds for one model](#several-feeds-for-one-model)
- [A/B testing two rankings](#ab-testing-two-rankings)
- [Your own presets](#your-own-presets)
- [Item stats and impressions](#item-stats-and-impressions)
- [Serving: API resources, Blade, Livewire, cursors](#serving-api-resources-blade-livewire-cursors)
- [Caching, warming and invalidation](#caching-warming-and-invalidation)
- [Scheduling and queues](#scheduling-and-queues)
- [Testing your feed](#testing-your-feed)

---

## Your own candidate source

A **source** proposes items. Use one for any rule the package cannot know: "same district as the viewer", "from shops they follow", "sponsored this week".

```php
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;

final class FollowedShopsSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        if ($context->isGuest()) {
            return [];
        }

        return Post::query()
            ->recommendable($context)                        // same visibility as the feed
            ->whereIn('shop_id', ShopFollow::where('user_id', $context->userId)->select('shop_id'))
            ->latest()
            ->limit($limit)
            ->pluck('created_at', 'id')
            ->map(fn ($at) => 1 / (1 + $at->diffInDays($context->now())))   // newer = higher
            ->all();                                          // [item id => score]
    }
}
```

Add it with a weight; weights are relative and every source is normalised to 0..1 before blending:

```php
'posts' => [
    'preset' => 'social',
    'model' => Post::class,
    'interactions' => [...],
    'sources' => [
        CoInteractionSource::class => 0.45,
        FollowedShopsSource::class => 0.30,
        TrendingSource::class => 0.25,
    ],
],
```

Rules of thumb: one or two indexed queries with a `LIMIT`; return `[]` for guests when the rule is personal; scores only need to be comparable within your source. Sources are resolved from the container, so constructor injection works. A source that throws is logged and skipped — the feed still serves. Helpers for turning queries into scores (`Math::scoreInOrder`, `fromAge`, `fromAffinity`) are in [maths §5](maths.md). Full examples: `examples/reviews` (`PetTypeMatchSource`) and `examples/events-advanced` (district, host, type).

To add items *only occasionally* (discovery), list the source under `exploration_sources` instead: it gets `exploration_ratio` of the slots, interleaved, rather than a blend weight.

## Your own booster

A **booster** re-scores items that are already candidates — it never adds new ones. Return a value in 0..1 per id:

```php
use SolutionForest\Recommender\Contracts\Booster;

final class FreshnessBooster implements Booster
{
    public function boost(Context $context, array $ids): array
    {
        return Post::whereKey($ids)->pluck('created_at', 'id')
            ->map(fn ($at) => exp(-$at->diffInDays($context->now()) / 14))
            ->all();
    }
}

'boosters' => [QualityBooster::class => 0.15, FreshnessBooster::class => 0.05],
```

Keep booster weights small; they shift order within the pool. The sponsored pattern (source **and** booster **and** a diversity cap) is in `examples/blog`.

## Passing extra context

Sources, boosters and the `recommendable` scope receive a `Context`, not the HTTP request:

| | |
|---|---|
| `$context->userId` / `isGuest()` | the viewer (`null` for guests and the shared trending list) |
| `$context->attribute('user')` | the user model, when you passed one |
| `$context->attribute('…')` | anything else you pass |
| `$context->feed->get('…')` | any key on the feed config, including your own (`'sponsored_ids' => [...]`) |
| `$context->now()` | the clock (fixed in tests) |

Pass extras per request — e.g. a tenant, locale or the device:

```php
Post::recommendedFor($request->user(), attributes: ['tenant' => $tenant->id, 'locale' => app()->getLocale()]);

// in a source or the scope
$query->where('tenant_id', $context->attribute('tenant'));
```

**Attributes that change which items appear** (a tenant, a region): lists are cached per user, so you must say so, or a list built for tenant A is served under tenant B:

```php
'posts' => [..., 'list_vary_by' => ['tenant']],
```

The ranked list, the shared trending list (guests and the fallback), `warm()` and `explain()` then keep one copy per value. The seen-set is split per `tenant` too (tenants have different items); `device` and `locale` keep one shared seen-set by default (override with `seen_vary_by`). It is bounded, so sharing it reduces repeats but does not guarantee their absence. Rules:

- List every attribute that changes the candidates; leaving one out is unsafe. Attributes that only change how a card looks belong in `cards_vary_by`.
- A cache key is not authorization: your `recommendable` scope must still filter by tenant.
- Every value multiplies the cached lists per user, so vary by few, low-cardinality attributes.
- Pass the same attributes to `warm($feed, $user, attributes: [...])`, `forgetUser($feed, $user, [...])` and `explain($feed, $user, attributes: [...])`.

**Cards that depend on an attribute** (translated titles, per-tenant prices): cards are cached and shared, so tell the cache which attributes they vary by:

```php
'posts' => [..., 'cards_vary_by' => ['locale']],   // 'locale' defaults to app()->getLocale()

public function toRecommendationCard(?Context $context = null): array
{
    return ['id' => $this->id, 'title' => $this->getTranslation('title', $context?->attribute('locale'))];
}
```

All variants of an item share one cache entry, so `forgetCard()` / the `Recommendable` trait refresh every language at once.

## Replacing a built-in piece with a class

Each part a `model` provides can be replaced by a class; a class set on the feed always wins.

| Key | Contract | Replace when |
|---|---|---|
| `interactions` | `InteractionSource` | one shared events table with a `type` column, events in another system, joins needed |
| `filter` | `ItemFilter` | visibility needs data the model scope cannot reach cheaply |
| `cards` | `CardTransformer` | a card needs aggregated data (counts, joins) — one query for all ids, no N+1 |
| `groups` | `DiversityGroups` | diversity keys are not plain model attributes |

`php artisan recommender:make-feed posts --classes` scaffolds the first three. Example of a single events table:

```php
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Interaction;

final class ActivityInteractions implements InteractionSource
{
    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        $rows = DB::table('activities')->where('created_at', '>', $from)->where('created_at', '<=', $to);
        foreach ($rows->lazyById(1000) as $row) {
            yield new Interaction($row->user_id, $row->post_id, $row->type, CarbonImmutable::parse($row->created_at));
        }
    }

    public function userHistories(DateTimeInterface $since, int $perUser): iterable { /* user id => [item ids, newest first] */ }

    public function recentItems(int|string $userId, int $limit): array { /* newest distinct item ids */ }
}

'interactions' => ActivityInteractions::class,
```

Card with aggregated fields: see `EventCardTransformer` in `examples/events-advanced`. Cards are cached and shared by every viewer — merge viewer-specific fields (`liked_by_me`) in your controller, not in the card.

## Interaction tables that do not fit the defaults

Before writing a class, try config:

```php
'interactions' => [
    'post_likes' => 'like',
    'bookmarks' => ['event' => 'like', 'item' => 'bookmarkable_id', 'user' => 'owner_id', 'timestamp' => 'saved_at', 'key' => 'uuid'],
],
'item_column' => 'post_id',          // default {model}_id
'user_column' => 'user_id',
'timestamp_column' => 'created_at',
'history_events' => ['like'],        // what counts for "similar items" (default: positive_events)
'interactions_connection' => 'analytics',
```

Ids can be integers, UUIDs or ULIDs, and may contain commas. `php artisan recommender:doctor` checks every table and column.

## Recording interactions

If you do not want to hand-write the insert (and keep it in step with the config), let the feed write it. `$post->recordInteraction($user, 'like', once: true)` uses the table and columns the feed already declares. Use `once: true` only for like-style tables that have a unique index on (user, item); never for views. Unliking is your own delete (scores are decayed counters). Details and failure rules: [reference → recording interactions](reference.md#recording-interactions).

## Several feeds for one model

Different surfaces often want different rankings over the same items — "for you" vs "more like this shop" vs "trending this week":

```php
'feeds' => [
    'posts' => ['preset' => 'social', 'model' => Post::class, 'interactions' => [...]],
    'posts_fresh' => [
        'preset' => 'social',
        'model' => Post::class,
        'interactions' => [...],
        'sources' => [FreshPostSource::class => 0.6, TrendingSource::class => 0.4],
        'exploration_ratio' => 0.0,
    ],
],

Post::recommendedFor($user, 'posts_fresh');
```

With more than one feed per model, `recommendedFor()` needs the name (the error tells you). Feeds share nothing but your tables: scores, neighbours, caches and the seen-set are per feed.

## A/B testing two rankings

Two feeds with different weights, and your code decides who gets which:

```php
$feed = $user && crc32((string) $user->id) % 2 ? 'posts_b' : 'posts';

return Post::recommendedFor($user, $feed);
```

Compare them with `Recommender::stats($feed, $items, days: 7)` (quality of what was shown, with impressions on) or your own click tracking. Remove the losing feed from config afterwards. Its rows stay in the `rec_*` tables until you delete them (`where('feed', 'posts_b')`); `prune` only visits configured feeds.

## Your own presets

A preset is just a named set of feed keys. Add yours next to the built-in `catalog`, `social` and `events`:

```php
// config/recommender.php
'presets' => [
    'jobs' => [
        'events' => ['view' => 1.0, 'save' => 4.0, 'apply' => 10.0],
        'positive_events' => ['save', 'apply'],
        'half_life_days' => 30,
        'exploration_ratio' => 0.05,
    ],
],

'feeds' => ['jobs' => ['preset' => 'jobs', 'model' => Job::class, 'interactions' => [...]]],
```

Order: package defaults < `defaults` < preset < the feed's own keys.

## Item stats and impressions

```php
Recommender::stats('products', [12, 15]);           // lifetime
Recommender::stats('products', $product, days: 7);  // last 7 days
$product->recommendationStats('products');
```

`quality` (0..1) is the like rate adjusted for how much evidence there is — 1 like from 1 view does not beat 25 from 40. Turn on `'impressions' => true` to use approximate first serves within the seen window as the denominator; guests count per serve. Details and limitations: [reference → how good is an item?](reference.md#how-good-is-an-item).

Use it for "top rated this week" lists, moderation queues (high exposure, low quality), or seller dashboards.

## Serving: API resources, Blade, Livewire, cursors

`Post::recommendedFor()` / `recommendations()` return a `FeedPage`:

```php
$page = Post::recommendedFor($request->user());

$page->items;        // cards, in order
$page->ids;          // their ids
$page->hasMore;
$page->origin;       // 'personal' | 'trending' | 'tail'
$page->nextCursor(); // token for ?cursor=

return $page;                                    // paginator JSON (+ next_cursor)
return $page->toCursorArray();                   // cursor-paginator JSON
return PostResource::collection($page->models());   // Eloquent models, ranked order, one query
```

Blade / Livewire: `{{ $page->toPaginator()->links() }}`, or follow `nextCursor()` in a "load more" button. Viewer-specific fields (liked, saved) belong here, merged into the cards, never inside `toRecommendationCard()`.

## Caching, warming and invalidation

| When | Call |
|---|---|
| user logs in / opens the app | `Recommender::warm('posts', $user)` — builds their list on the `recommender` queue |
| item edited / unpublished | nothing with `use Recommendable` (cards refresh on save); otherwise `Recommender::forgetCard('posts', $id)` or `forgetModel($post)` |
| user blocks someone / changes preferences | `Recommender::forgetUser('posts', $user)` (also clears what they have seen) |
| weights or sources changed | `Recommender::forgetTrending('posts')`; user lists refresh within `feed_ttl` |
| new event weights / half-life for past data | `php artisan recommender:rebuild-scores posts --days=…` |
| emergency | `RECOMMENDER_ENABLED=false` — everyone gets the shared trending list, no per-user work |

Use a cache store with locks (redis, or database + `cache_locks`) in production so concurrent builds for one user do not race.

## Scheduling and queues

The package schedules `update-scores` (every 10 min), `build-neighbours` (nightly) and `prune` (daily) itself. Change the times, or take over:

```php
'schedule' => ['scores' => '*/5 * * * *', 'neighbours' => '0 2 * * *', 'prune' => '0 4 * * *'],
// RECOMMENDER_SCHEDULE=false → schedule the commands yourself
```

`warm()` dispatches `BuildFeed` to the `recommender` queue (`RECOMMENDER_QUEUE`, `RECOMMENDER_QUEUE_CONNECTION`); run a worker for it. Large catalogs: see [operations → memory and 100× data](operations.md#memory).

## Testing your feed

Feeds are plain config, so tests can define one, write a few rows and assert on the result:

```php
it('recommends what similar readers liked', function () {
    config(['recommender.feeds.posts' => [
        'model' => Post::class,
        'interactions' => ['post_likes' => 'like'],
        'exploration_ratio' => 0,          // deterministic order
    ]]);

    [$a, $b] = Post::factory()->count(2)->create();
    foreach (User::factory()->count(3)->create() as $reader) {
        PostLike::create(['user_id' => $reader->id, 'post_id' => $a->id]);
        PostLike::create(['user_id' => $reader->id, 'post_id' => $b->id]);
    }
    $me = User::factory()->create();
    PostLike::create(['user_id' => $me->id, 'post_id' => $a->id]);

    $this->travel(2)->minutes();           // update-scores skips the last minute
    $this->artisan('recommender:update-scores', ['feed' => 'posts']);
    $this->artisan('recommender:build-neighbours', ['feed' => 'posts']);

    expect(Post::recommendedFor($me)->ids)->toContain($b->id);
});
```

`php artisan recommender:doctor posts` in CI catches schema drift; the dashboard's *Explain a user* shows why an item ranked where it did.
