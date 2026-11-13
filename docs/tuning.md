# Fine-tuning weights

Weights are **relative**. Bigger number = that signal counts more.  
Exploration **interleaves** slots (`exploration_ratio`) — that is not the same as raising a source weight.

Start from a preset (`'preset' => 'catalog' | 'social' | 'events'`, see [reference → presets](reference.md#presets)) and change one setting at a time — anything you set on the feed overrides the preset. [Getting started](getting-started.md) explains the terms.

## Event weights (any interaction type → trending)

**Not limited to likes/views.** Any event name in your `interactions` table map (or yielded by an `InteractionSource`) can be a weighted event (`share`, `bookmark`, `comment`, …).

```php
'events' => [
    'view'  => 1.0,
    'like'  => 4.0,
    'share' => 6.0,  // optional third+ types
],
'positive_events' => ['like', 'share'],
'exposure_events' => ['view'],
```

Used by `update-scores` when folding events into `hot`. Only keys in `events` with weight `> 0` count. Raise stronger actions if they should dominate trending; raise `view` if browse volume should matter more. The updater only processes events after its watermark: a rerun does not recalculate past events with new weights. To apply new weights to past events, run `recommender:rebuild-scores {feed}` ([operations](operations.md#historical-imports-and-score-rebuilds)).

For neighbours (`build-neighbours`), only strong actions count by default: `history_events`, which defaults to `positive_events` (e.g. likes, joins, purchases — not every view). With an `InteractionSource` class, return the same from `userHistories()` / `recentItems()`.

## Sponsors (certain posts / shops more often)

1. List ids: `sponsored_author_ids` or `sponsored_shop_ids`
2. `SponsoredShopSource` weight → how often they enter the candidate pool
3. `SponsoredShopBooster` weight → how hard they are pushed in the blend
4. Keep `diversity` so one sponsor cannot own every slot

See [`examples/blog`](../examples/blog).

Your own sources and boosters: see [advanced usage](advanced.md).

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

**D. Classic "random from the shops I looked at" feed**

Keep one rule source and randomise the order. The list is shuffled once per build (`feed_ttl`) and stays stable while cached, so paging never repeats and the database never runs `ORDER BY RAND()`.

```php
'sources' => [
    RelatedShopSource::class => 1.0,    // your CandidateSource: reviews of recently viewed / related shops
],
'boosters' => [],
'exploration_ratio' => 0.0,
'jitter' => 1.0,                        // uniform random order, like the old feed
'source_limit' => 600,                  // more of each shop's reviews to shuffle from
```

Lower `jitter` (e.g. `0.3`) or add `CoInteractionSource` later to move gradually from "random" to "personal".

**C. Cold start / guests** — guests use `trending_sources`; an authenticated user can have trending candidates in a personal list. Keep a guest-compatible source and enough eligible scored items. Neither case guarantees a nonempty feed.

## How to tune

1. Pick 2–3 real users with different signals.
2. `php artisan recommender:explain {feed} {userId} --limit=12`.
3. If you only see `Fresh*` / `Trending*`, personal sources may be weak or empty. `explain` builds a fresh ranking: rows are in score order, with each item's `final` position (after exploration and diversity) and `cached` position in the served list.
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
| `exploration_ratio` | Controls interleaving from configured exploration sources (empty by default) |
| `jitter` | Randomness per list build (0 … 1). `1` = uniform random order of the candidates, like a classic `ORDER BY RAND()` feed; `0.3` keeps the best near the top but varies the order between builds |
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
| First paint for new users | Trending can help; filtering, missing cards or sparse data can still yield an empty page |
| Scroll ends too early | Give the feed a `model` (catalog tail is on by default) and check `tail` is not `false` |
| Media | Map `.mp4` → poster in `toRecommendationCard()` |
| Sponsored shops | Source + Booster + diversity caps |
