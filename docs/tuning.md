# Fine-tuning weights

Weights are **relative**. Bigger number = that signal counts more.  
Exploration **interleaves** slots (`exploration_ratio`) — that is not the same as raising a source weight.

Quick “events / sponsors” overview also on the [README](../README.md#how-to-adjust-the-ratio).

## Event weights (any interaction type → trending)

**Not limited to likes/views.** Any string you yield from `InteractionSource` can be a weighted event (`share`, `bookmark`, `comment`, …).

```php
'events' => [
    'view'  => 1.0,
    'like'  => 4.0,
    'share' => 6.0,  // optional third+ types
],
'positive_events' => ['like', 'share'],
'exposure_events' => ['view'],
```

Used by `update-scores` when folding events into `hot`. Only keys in `events` with weight `> 0` count. Raise stronger actions if they should dominate trending; raise `view` if browse volume should matter more. Re-run `recommender:update-scores` after big changes.

For neighbours (`build-neighbours`), put the items you care about in `userHistories()` / `recentItems()` — usually strong actions, not every view.

## Sponsors (certain posts / shops more often)

1. List ids: `sponsored_author_ids` or `sponsored_shop_ids`
2. `SponsoredShopSource` weight → how often they enter the candidate pool
3. `SponsoredShopBooster` weight → how hard they are pushed in the blend
4. Keep `diversity` so one sponsor cannot own every slot

See [`examples/blog`](../examples/blog).

## Recipes

**A. Inspiration / “for you” (personal first)**

```php
'sources' => [
    CoInteractionSource::class => 0.35,
    RelatedShopSource::class => 0.20,   // your CandidateSource
    PetMatchSource::class => 0.25,      // your CandidateSource
    TrendingSource::class => 0.15,
    FreshReviewSource::class => 0.05,   // your CandidateSource
],
'boosters' => [
    QualityBooster::class => 0.20,
    FreshnessBooster::class => 0.05,    // keep small
],
'exploration_ratio' => 0.10,
```

**B. Fresher home feed**

```php
'sources' => [
    FreshReviewSource::class => 0.45,
    CoInteractionSource::class => 0.25,
    TrendingSource::class => 0.20,
],
'boosters' => [
    FreshnessBooster::class => 0.35,
    QualityBooster::class => 0.10,
],
'exploration_ratio' => 0.25,
'half_life_days' => 3,
```

Raising freshness a lot makes **every cohort look the same**. Prefer recipe A for segment demos.

**C. Cold start / guests** — built in: fall back to `trending_sources`. Keep at least one source that works with `$context->userId === null`.

## How to tune

1. Pick 2–3 real users with different signals.
2. `php artisan recommender:explain {feed} {userId} --limit=12`.
3. If you only see `Fresh*` / `Trending*`, personal sources are weak or empty.
4. Change **one** weight; forget user + trending cache; re-explain.
5. Watch latency: `source_limit` / `feed_size` / heavy SQL dominate cold builds.

After config changes:

```php
Recommender::forgetUser('reviews', $userId);
Recommender::forgetTrending('reviews');
```

## Knobs that are not weights

| Knob | Effect |
|---|---|
| `exploration_ratio` | Slots reserved for exploration pool |
| `half_life_days` | How fast trending forgets old events |
| `history_days` / `neighbours_min_co_count` | Neighbour graph density |
| `recent_items` | How many recent items seed co-interaction |
| `feed_size` / `trending_size` / `seen_max` | Unique scroll length |
| `diversity` | Cap per shop/author per page (needs `groups`) |

## Lessons from a real inspiration feed

| Observation | Takeaway |
|---|---|
| “Latest first” fights personalisation | Keep freshness as spice |
| Peer signal ≠ content topic | “Same pet as writer” ≠ “card about cats” |
| Empty `CoInteractionSource` | Neighbours never built / history too short |
| Huge journey payloads | Prefer recent ids; don’t load multi-thousand JSON to slice 20 |
| Source SQL | Indexed `whereIn` + PHP cap, or bounded `UNION ALL` of per-id `LIMIT`s |
| First paint for new users | Trending + exploration; page 1 never empty if scores exist |
| Scroll is long, not infinite | App-level catalog tail after `hasMore` — see `InfiniteScrollFeed` |
| Media | Map `.mp4` → poster in `CardTransformer` |
| Sponsored shops | Source + Booster + diversity caps |

Wishlist ideas (`recommender:bootstrap`, named presets, richer explain): see [operations](operations.md#roadmap).
