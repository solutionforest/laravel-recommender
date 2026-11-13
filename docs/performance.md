Measured results from the package's own performance tests. Each one is a Pest test that **fails** when a count is wrong, an item repeats, a query or memory budget is exceeded, or anything logs a warning or error. The numbers below are what those tests printed. Re-run them on your hardware with the commands at the end.

## Summary

| Question | Answer | Test |
|---|---|---|
| How fast is a page? | **0.2 ms** warm, **5–8 ms** when the list is built (p50). Max 7–9 queries. | Stress, Heavy |
| Does it slow down as history grows? | No. Page cost stays flat from 100k to 1M events. | Stress, Heavy |
| How fast are scores updated? | **64k–165k events/s** with +4–28 MB memory | Stress, Heavy |
| 1M interactions in 10 minutes? | Counted exactly, 0 errors, pages p95 **10 ms** while scoring | Heavy |
| Many processes on the database cache? | **0 deadlocks, 0 errors** up to 48 processes × 200 pages | Concurrency |
| Events every minute, bursts? | Every event counted exactly once | Stress |
| Fast scrolling? | 60 pages in 75–126 ms, 0 repeats, 1 build | Stress |
| Laravel Octane? | No repeats across requests, impressions written, config isolated per request | Octane |

## Test machine

Deliberately small. A normal production server will be faster.

| | |
|---|---|
| CPU | **1 vCPU** (sandboxed VM) |
| Memory | 32 GB |
| PHP | 8.4.27 (CLI, no OPcache JIT) |
| Databases | SQLite 3.40.1 (in memory); MariaDB 10.11.19 (local, `innodb_buffer_pool_size=2G`) |
| Package | v0.8.4, run on 2026-11-13 |

Each concurrency worker is a forked PHP process. All of them share the one CPU with MariaDB, so pages/s there is a floor, not a ceiling.

## 1. Data volume: `StressTest`

The config-only example feed (`examples/simple`) with 1,000 items in 50 clusters. Users like items in their own cluster and view items anywhere, over 9 days.

| Step | Scale | SQLite | MariaDB |
|---|---|---|---|
| `update-scores` | 100,000 events, 2,000 users | 0.84 s · **118,906 ev/s** · 230 queries · +4 MB | 1.22 s · 81,943 ev/s · 230 queries · +4 MB |
| `update-scores` | 500,000 events, 10,000 users (`SCALE=5`) | 4.19 s · **119,214 ev/s** · 633 queries · +14 MB | 3.02 s · 165,519 ev/s · 633 queries · +14 MB |
| `build-neighbours` | 2,000 users | 0.05 s · 9 reads | 0.07 s · 9 reads |
| `build-neighbours` | 10,000 users (`SCALE=5`) | 0.15 s · 41 reads | 0.30 s · 41 reads |

What the test asserts:
- every event is counted;
- fewer than 1 query per 100 events (scores are written in bulk, never per event);
- memory does not grow with the number of events (< 96 MB);
- users are read in chunks of 500;
- no item has more than 30 neighbours.

### Serving cost: 200 users, page 1 then page 2

| | SQLite p50 / p95 | MariaDB p50 / p95 | Max queries |
|---|---|---|---|
| Page 1, list built now (cold) | 5.0 / 5.4 ms | 8.2 / 16.3 ms | 7 |
| Page 2, list cached (warm) | **0.2 / 0.4 ms** | **0.2 / 1.0 ms** | 1 |

SQLite gives the same numbers at 100k and 500k events (scale 1 and 5): **serving cost does not grow with history**. Asserted: cold ≤ 12 queries, warm ≤ 2.

## 2. Interaction frequency: `StressTest`

| Scenario | Result |
|---|---|
| Events every minute for 2 hours: some exactly on the minute (slice boundary), some mid-minute, some 1 s before "now" (inside the safety margin), plus **1,000 likes from one user in the same second**. Scores updated every minute: 120 runs. | 1,720 inserted → **1,720 counted**, none twice. The burst item leads trending. |
| One user scrolls 60 pages back to back | 600 items, **0 repeats**, 1 list build. 75 ms on SQLite, 126 ms on MariaDB (1.2–2.1 ms/page). |
| Pull-to-refresh: page 1 × 100 within the rebuild interval | **0 rebuilds** (throttled by `min_rebuild_interval`). 261–375 ms total. |
| Items unpublished / edited between requests | Hidden items never rendered, pages still full, edited cards refreshed |

## 3. One million interactions at once: `HeavyStressTest`

1,000,000 interactions from 500,000 users on 5,000 items, **all inside one 10-minute window**:
- 600k views, 300k likes, 100k shares;
- a few very hot items and a long tail.

Then, for 6 more minutes, each minute adds 10,000 new events and runs `update-scores`. Right after each run, 150 members load 2 pages each and 20 guests load one. Neighbours are rebuilt mid-way while pages keep being served. MariaDB:

| Step | Result |
|---|---|
| Seed 1M rows | 10.2 s |
| `update-scores` over 1M events | **15.7 s (63,730 ev/s)**, 1,655 queries, +28 MB |
| `build-neighbours` | 275,548 active users in 78.9 s |
| 1,800 member pages served during the bursts | **p50 5.4 ms / p95 10.2 ms**, max 9 queries per user |
| Peak memory (whole test) | 102 MB |
| Counts after the bursts | positive and exposure totals **exact** |
| Repeats while scrolling | 0 |
| Warnings / errors logged | **0** |

## 4. Many processes at once: `ConcurrencyTest`

Forked PHP processes serve feeds at the same moment. Setup:
- MariaDB with the **database cache store** (the worst case; Redis is lighter);
- impressions on;
- 300 users, so the same user is often served by several processes at once.

Meanwhile one process keeps inserting likes and running `update-scores`. 10% of requests are guests, pages 1–3.

| Processes × pages each | Pages | Time | Pages/s | Errors | Deadlocks | Impressions written |
|---|---|---|---|---|---|---|
| 12 × 150 | 1,800 | 6.0 s | 299 | 0 | **0** | 17,670 |
| 24 × 300 | 7,200 | 26.5 s | 272 | 0 | **0** | 69,742 |
| 48 × 200 | 9,600 | 31.7 s | 303 | 0 | **0** | 89,848 |

Before the 0.8.2 write-order fix, the 12 × 150 run produced **213 deadlocks** in impression writes. This test now guards against that regression. The cache table stays at about 1,000 rows (per-user rows, expired ones removed by `recommender:prune`). All processes share 1 vCPU with MariaDB, so throughput is a floor.

## 5. Large-data safety: `ScaleTest` (normal suite)

These check behaviour rather than speed:
- neighbours are the same with any number of shards;
- a memory-pressure run escalates shards and never leaves a half-built version;
- the memory budget is capped below `memory_limit`;
- neighbours are written with bulk inserts, and scores are merged with one upsert per chunk;
- a crash and resume never counts an event twice;
- idle score rows are pruned in chunks;
- lists and seen lists are stored packed.

## 6. Long-lived workers: `OctaneTest` (normal suite)

Pages are served through an Octane-style worker loop: clone the app per request, forget scoped instances, terminate, flush. The test checks:
- no repeats across requests;
- impressions are written in the terminate step;
- switching the package off in one request does not leak into the next;
- a feed config changed at runtime applies only to its own request.

## Reproduce

```bash
# Stress (normal suite; print the numbers, optionally 5× larger)
RECOMMENDER_PERF=1 vendor/bin/pest --group=stress
RECOMMENDER_PERF=1 RECOMMENDER_STRESS_SCALE=5 vendor/bin/pest --group=stress

# Same on MySQL / MariaDB
RECOMMENDER_PERF=1 RECOMMENDER_TEST_DB=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rec_test DB_USERNAME=root vendor/bin/pest --group=stress

# 1M events (~3–4 min); tune RECOMMENDER_HEAVY_EVENTS / RECOMMENDER_HEAVY_USERS
RECOMMENDER_HEAVY=1 RECOMMENDER_TEST_DB=mysql … vendor/bin/pest --group=heavy

# Concurrency (needs pcntl); tune RECOMMENDER_CONCURRENCY_WORKERS / RECOMMENDER_CONCURRENCY_REQUESTS
RECOMMENDER_CONCURRENCY=1 RECOMMENDER_TEST_DB=mysql … vendor/bin/pest --group=concurrency
```

CI runs the stress, scale and Octane tests on every push: SQLite on PHP 8.3/8.4 × Laravel 11/12/13, plus MySQL 8. The heavy and concurrency tests are opt-in because they need minutes and a real database.

## What this means for your app

- **Pages:** a page is a few cache reads plus at most one primary-key query for cards that are not cached yet. Cost depends on page size, not on how many interactions you have.
- **Scores:** at about 60k events/s on one small CPU, a 10-minute `update-scores` run keeps up with roughly **35M events per 10 minutes** before it would overlap. Overlap is prevented anyway (`withoutOverlapping`).
- **Database cache:** fine for small and medium apps (no deadlocks under 48 concurrent processes). For heavy traffic add Redis to the app; the package picks it up automatically.
- **Your own SQL matters more than ours:** index the interaction tables on `created_at` and `(user_id, created_at)`. See [operations → Your SQL matters](operations.md#your-sql-matters).
