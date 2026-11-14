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

## Sponsored items (some authors or brands more often)

The blog example shows the pattern ([`examples/blog`](../examples/blog)):

1. List the ids on the feed: `'sponsored_author_ids' => [3, 7]` (any custom key works; read it with `$context->feed->get()`).
2. `SponsoredAuthorSource` weight → how often their items enter the candidate pool.
3. `SponsoredAuthorBooster` weight → how hard they are pushed in the blend.
4. Keep `diversity` so one sponsor cannot own every slot.

Your own sources and boosters: see [advanced usage](advanced.md).

## Recipes

Classes marked *yours* are `CandidateSource` / `Booster` classes you write ([advanced usage](advanced.md)); `examples/` has working ones (`FreshPostSource` and `FreshnessBooster` in `examples/blog`, `PetTypeMatchSource` in `examples/reviews`).

**A. Personal "for you" feed (personal first)**

```php
'sources' => [
    CoInteractionSource::class => 0.45,
    SameCategorySource::class => 0.25,  // yours: e.g. items in categories the user follows
    TrendingSource::class => 0.20,
    FreshPostSource::class => 0.10,     // yours: newest items
],
'boosters' => [
    QualityBooster::class => 0.20,
    FreshnessBooster::class => 0.05,    // yours; keep small
],
'exploration_ratio' => 0.10,
```

**B. Fresher home feed**

```php
'sources' => [
    FreshPostSource::class => 0.45,     // yours
    CoInteractionSource::class => 0.25,
    TrendingSource::class => 0.20,
],
'boosters' => [
    FreshnessBooster::class => 0.35,    // yours
    QualityBooster::class => 0.10,
],
'exploration_ratio' => 0.25,
'half_life_days' => 3,
```

Raising freshness a lot makes **every user's feed look the same** (everyone gets the newest items). Prefer recipe A when feeds should differ per user.

**C. Cold start / guests** — guests use `trending_sources`; an authenticated user can have trending candidates in a personal list. Keep a guest-compatible source and enough eligible scored items. Neither case guarantees a nonempty feed.

**D. Random order from a rule ("random items from what I looked at")**

Keep one rule source and randomise the order. The list is shuffled once per build (`feed_ttl`) and stays stable while cached, so paging never repeats and the database never runs `ORDER BY RAND()`.

```php
'sources' => [
    RecentlyViewedSource::class => 1.0, // yours: e.g. items related to what the user viewed recently
],
'boosters' => [],
'exploration_ratio' => 0.0,
'jitter' => 1.0,                        // uniform random order of the candidates
'source_limit' => 600,                  // a bigger pool to shuffle from
```

Lower `jitter` (e.g. `0.3`) or add `CoInteractionSource` later to move gradually from "random" to "personal".

## How to tune

1. Pick 2–3 real users with different signals.
2. `php artisan recommender:explain {feed} {userId} --limit=12`.
3. If you only see `TrendingSource` (or your freshness sources), personal sources may be weak or empty. `explain` builds a fresh ranking: rows are in score order, with each item's `final` position (after exploration and diversity) and `cached` position in the served list.
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
| `diversity` | Cap per group (author, category, …) per page; groups come from model attributes, or a `groups` class |

## Common pitfalls

| Observation | Takeaway |
|---|---|
| "Latest first" fights personalisation | Keep freshness as a small weight |
| Empty `CoInteractionSource` | Neighbours never built, history too short, or `neighbours_min_co_count` too high |
| Slow cold builds | Each source: indexed query + `LIMIT`; for "newest per owner" prefer a bounded `UNION ALL` of per-owner `LIMIT`s ([operations](operations.md#your-sql-matters)) |
| Big per-user history | Use recent ids (`recent_items`); don't load thousands of rows to keep 20 |
| Empty first page for new users | Trending helps; filtering, missing cards or sparse data can still give an empty page |
| Scroll ends too early | Give the feed a `model` (catalog tail is on by default) and check `tail` is not `false` |
| One author fills a page | `diversity` caps |
