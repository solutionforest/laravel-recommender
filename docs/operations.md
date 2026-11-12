# Operations

Go-live, storage, locks, FAQ, roadmap.

## First-time go-live checklist

| Step | Action | Why |
|---|---|---|
| 1 | `php artisan migrate` | `rec_*` tables |
| 2 | Scaffold a feed | `php artisan recommender:make-feed posts` (config + 3 classes) |
| 3 | Index your interaction / item tables | Sources are your SQL |
| 4 | `recommender:update-scores {feed}` | Fill `hot` / quality |
| 5 | `recommender:build-neighbours {feed}` | Fill graph |
| 6 | `recommender:explain {feed} {userId}` | Expect personal sources |
| 7 | Schedule three jobs | See [reference](reference.md#background-jobs) |
| 8 | Cache with locks (`redis` or `database`+`cache_locks`) + `queue:work --queue=recommender` | Build locks + warm builds |

### Historical imports and score rebuilds

The first score run reads `scores_backfill_days` (default 30); later runs start at the stored watermark. Increasing the setting after a run does not revisit older events. Likewise, changing event weights or half-life does not recalculate past scores.

For a deliberate full rebuild of one feed, first back up the database, stop overlapping score updates for that feed, and set `scores_backfill_days` wide enough for the intended history. On the configured recommender database connection, delete only that feed's rows from `rec_item_scores` and the `rec_state` key `scores:{feed}:watermark` together in a transaction. Do not truncate the shared table or clear only the watermark: replaying onto existing scores double-counts events. Run `recommender:update-scores {feed}`, check its output and `status`, then restore the setting if desired. Rebuild neighbours separately with an appropriate `history_days`. Invalidate cached trending and affected user lists (or wait for their TTLs), and resume the schedule. Plan for load and a temporary loss of scores during the rebuild.

### Livewire / HTTP `Artisan::call`

Commands register outside CLI so Ops panels work. Prefer queued jobs in production.

## Publishing a package release

GitHub Releases are automated: push a `v*.*.*` tag → [`.github/workflows/release.yml`](../.github/workflows/release.yml) creates/updates the Release using the matching `## [x.y.z]` section from [`CHANGELOG.md`](../CHANGELOG.md).

1. Move notes out of `## [Unreleased]` into a new `## [x.y.z] — YYYY-MM-DD` section (Keep a Changelog).
2. Add upgrade steps to [`UPGRADE.md`](../UPGRADE.md) for anything that changes behaviour, and bump `extra.branch-alias` in `composer.json` on a new minor (`0.2.x-dev`).
3. Commit on `main`, wait for [tests](../.github/workflows/tests.yml) to go green.
4. Tag and push:

```bash
git tag -a v0.1.1 -m "v0.1.1"
git push origin main
git push origin v0.1.1
```

5. Confirm the Release on GitHub (notes = that CHANGELOG section). Re-run from **Actions → release → Run workflow** if you only need to refresh notes for an existing tag.

A Git tag triggers the workflow; check that the corresponding GitHub Release was created successfully.

## Day-to-day ops

| Task | How |
|---|---|
| Stop personalisation | `RECOMMENDER_ENABLED=false` |
| Flush cache | Delete keys `rec:*` (or your prefix) |
| Reset one user | `Recommender::forgetUser('posts', $id)` |
| Debug ranking | `recommender:explain posts 123` |
| Health | `recommender:status` / `recommender:status posts` |
| Rebuild similarities | `recommender:build-neighbours posts` |
| Recompute scores | Follow the feed-scoped [rebuild procedure](#historical-imports-and-score-rebuilds) |
| Neighbour memory guard | Retries with more shards; raise budget or lower `history_per_user` |

Source and build failures are logged and can fall back to trending. Other failures (for example a missing feed or card transformer) may still surface; check application logs.

## What is stored where

Package **never copies** your interaction rows (likes / views / shares / …). Three derived tables + cache:

| Store | Holds | Grows with | Bounded by |
|---|---|---|---|
| `rec_item_scores` | `hot`, counters | recently active items | `prune` + retention |
| `rec_item_neighbours` | top-N similar ids | items with neighbours | versioned rebuild |
| `rec_state` | watermarks, versions | handful of keys | overwrite |
| Cache store (redis / database / …) | lists, seen, cards, locks | active users | TTLs |

~100 B/score row, ~400 B/neighbour row → 100k active items ≈ tens of MB. Redis ≈ 1.5 KB list + 1.5 KB seen per active user.

## Deadlocks & overlapping runs

| Concern | Handling |
|---|---|
| Two score jobs | `withoutOverlapping()`; short per-slice transactions |
| Score job vs live traffic | Hot path reads cache, not `rec_*` |
| Neighbour mid-request | New version → activate → chunked delete of old |
| Two builds same user | Cache lock when store supports `LockProvider`; otherwise unlocked fallback (last write wins) |
| Warm pile-up | `BuildFeed` `ShouldBeUnique` |
| App-table deadlocks | Jobs only **SELECT** your interaction tables |

Crash mid-slice: watermark / version activation → resume cleanly, no double-count.

## Memory

| Path | Behaviour |
|---|---|
| HTTP `page()` | Cache reads plus card queries; a cache miss can trigger a full feed build |
| `update-scores` | one time slice |
| `build-neighbours` | shards; abort/retry under memory budget |
| `prune` | `delete_chunk` id lists |

Tight host: lower `history_*`, raise shards, shorten `scores_slice_minutes`.

## At 100× data

| Part | Grows with | At 100× |
|---|---|---|
| Request | cache state and page size | cache reads, card fetches, and occasional feed builds |
| Feed build | active users | more builds, same size each |
| `update-scores` | events / 10 min | bigger slices |
| `build-neighbours` | users × capped history | more shards |
| Cache (lists + seen) | active users | ~3 KB / active user |

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
No. Any `LockProvider` store works. **Redis** is the best production default; **database** works on Laravel 11–13 with `cache_locks`; **array/file** are fine locally. See [reference → Cache](reference.md#cache-store--locks).

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
The initial window defaults to 30 days. Widen it before the first run, or follow the feed-scoped rebuild procedure above.

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
