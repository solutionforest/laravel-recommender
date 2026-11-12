# Fine-tuning weights

Weights are **relative**. Exploration **interleaves** slots (`exploration_ratio`) — that is not the same as raising a source weight.

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
