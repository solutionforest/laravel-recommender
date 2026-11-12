# Operations

Go-live, storage, locks, FAQ, roadmap.

## First-time go-live checklist

| Step | Action | Why |
|---|---|---|
| 1 | `php artisan migrate` | `rec_*` tables |
| 2 | Publish config | `vendor:publish --tag=recommender-config` — then define a feed + wire classes |
| 3 | Index your interaction / item tables | Sources are your SQL |
| 4 | `recommender:update-scores {feed}` | Fill `hot` / quality |
| 5 | `recommender:build-neighbours {feed}` | Fill graph |
| 6 | `recommender:explain {feed} {userId}` | Expect personal sources |
| 7 | Schedule three jobs | See [reference](reference.md#background-jobs) |
| 8 | Redis + `queue:work --queue=recommender` | Prod locks + warm builds |

### Historical imports

Default `scores_backfill_days` is **30**. For years of imports:

1. Truncate `rec_item_scores` for that feed and delete `rec_state` key `scores:{feed}:watermark`, **or**
2. Temporarily set large `scores_backfill_days` / `scores_slice_minutes`, run `update-scores`, then restore.

Then rebuild neighbours with enough `history_days`.

### Livewire / HTTP `Artisan::call`

Commands register outside CLI so Ops panels work. Prefer queued jobs in production.

## Day-to-day ops

| Task | How |
|---|---|
| Stop personalisation | `RECOMMENDER_ENABLED=false` |
| Flush cache | Delete keys `rec:*` (or your prefix) |
| Reset one user | `Recommender::forgetUser('posts', $id)` |
| Debug ranking | `recommender:explain posts 123` |
| Health | `recommender:status` / `recommender:status posts` |
| Rebuild similarities | `recommender:build-neighbours posts` |
| Recompute trending from scratch | Truncate scores + clear watermark → `update-scores` |
| Neighbour memory guard | Retries with more shards; raise budget or lower `history_per_user` |

Failures log as warnings; request falls back to trending.

## What is stored where

Package **never copies** likes/views. Three derived tables + cache:

| Store | Holds | Grows with | Bounded by |
|---|---|---|---|
| `rec_item_scores` | `hot`, counters | recently active items | `prune` + retention |
| `rec_item_neighbours` | top-N similar ids | items with neighbours | versioned rebuild |
| `rec_state` | watermarks, versions | handful of keys | overwrite |
| Redis | lists, seen, cards, locks | active users | TTLs |

~100 B/score row, ~400 B/neighbour row → 100k active items ≈ tens of MB. Redis ≈ 1.5 KB list + 1.5 KB seen per active user.

## Deadlocks & overlapping runs

| Concern | Handling |
|---|---|
| Two score jobs | `withoutOverlapping()`; short per-slice transactions |
| Score job vs live traffic | Hot path reads cache, not `rec_*` |
| Neighbour mid-request | New version → activate → chunked delete of old |
| Two builds same user | Cache lock when available; lab unlocked = last write wins |
| Warm pile-up | `BuildFeed` `ShouldBeUnique` |
| App-table deadlocks | Jobs only **SELECT** your interaction tables |

Crash mid-slice: watermark / version activation → resume cleanly, no double-count.

## Memory

| Path | Behaviour |
|---|---|
| HTTP `page()` | O(page) |
| `update-scores` | one time slice |
| `build-neighbours` | shards; abort/retry under memory budget |
| `prune` | `delete_chunk` id lists |

Tight host: lower `history_*`, raise shards, shorten `scores_slice_minutes`.

## At 100× data

| Part | Grows with | At 100× |
|---|---|---|
| Request | nothing | same cache + optional PK card fetch |
| Feed build | active users | more builds, same size each |
| `update-scores` | events / 10 min | bigger slices |
| `build-neighbours` | users × capped history | more shards |
| Redis | active users | ~3 KB / active user |

Writes are always bulk (`write_chunk` / `delete_chunk`). Asserted in `tests/Feature/ScaleTest.php`.

### Your SQL matters

Measured (MariaDB 10.11, 1M rows) — newest reviews of 30 shops:

| Query | Time |
|---|---|
| `WHERE shop_id IN (…) ORDER BY created_at DESC LIMIT 200` | ~470 ms |
| Same + index still sorts every row | ~468 ms |
| `UNION ALL` of 30 × `(shop_id = ? … LIMIT 14)` | **~3.6 ms** |

## FAQ

**Better than AWS Personalize?**  
Not as pure ML. For sparse likes/views, item–item + rules is a proven baseline: no per-request cloud cost, likes count in ~10 min, explainable cards.

**Slow DB when data grows?**  
Request path doesn’t rank in SQL. Jobs are windowed, bulk, memory-guarded.

**Need Redis?**  
Production: yes. Lab: array/file/database OK (unlocked fallback).

**Will jobs deadlock us?**  
Unlikely for the usual fear case — see deadlocks table above.

**Will `build-neighbours` OOM?**  
Shards + retry; previous graph stays live.

**How often to update?**  
Scores ~10 min, neighbours nightly, prune daily.

**A/B test?**  
Controller chooses who gets `Recommender::page()`.

**Add a learned model later?**  
Implement `CandidateSource` + weight.

**Same cards for all cohorts?**  
Freshness too high, or peer source matches multi-segment writers. See [tuning](tuning.md).

**No CoInteraction in explain?**  
Neighbours never built / history short / `neighbours_min_co_count` too high.

**Imported old events, still empty?**  
`scores_backfill_days` default 30 — widen or reset watermark.

**Infinite feed?**  
No — long pool then `hasMore` false / seen recycle. Catalog tail is app-side (`InfiniteScrollFeed`).

## Roadmap / wishlist

- Impressions (shown but not clicked)
- Cursor pagination API (pages today = seen-set + paginator metadata)
- In-package catalog tail
- User–user / embedding sources (you can add as `CandidateSource` today)
- Item ids must not contain commas (packed `id,id,…` cache)
- DX: `recommender:bootstrap`, named presets, backfill flags, richer explain / health

## Limitations

- No impressions yet
- No cursor API yet
- Item-to-item only out of the box
- Ids must not contain `,`
