# Agent guidelines — solutionforest/laravel-recommender

Use this when changing the package or helping an app integrate it. Full detail: [README.md](README.md).

## Mental model

- Package **never copies** raw events; it reads app tables via `InteractionSource`.
- Own tables: `rec_item_scores`, `rec_item_neighbours`, `rec_state` only.
- Hot path: cache list + seen-set + cards. Ranking rebuild is bounded (`source_limit`, `feed_size`).

## First-time setup (do not skip)

1. Migrate package tables.
2. `recommender:update-scores {feed}` then `recommender:build-neighbours {feed}`.
3. `recommender:explain {feed} {activeUserId}` — personal sources should appear.
4. Schedule update-scores / build-neighbours / prune; Redis + `recommender` queue in prod.

Historical imports: widen `scores_backfill_days` + `scores_slice_minutes`, or clear watermark + scores, then rebuild neighbours.

## Fine-tuning

- Weights are relative; `exploration_ratio` is separate (slot interleave).
- **Inspiration / segments:** personal sources high, Fresh\* / FreshnessBooster low (~0.05–0.10).
- **Latest-first:** raises Fresh\* — makes cohorts look alike; avoid for pet/segment demos.
- After weight changes: `forgetUser` / `forgetTrending` (or flush `rec:*`).
- Tune with 2–3 real users + `explain`, one knob at a time.

## Implementing sources (app code)

- Guests: `$context->userId` may be `null` — trending path must still work.
- One/two indexed queries + `LIMIT`; avoid huge `WHERE user_id IN (subquery of tens of thousands)`.
- Prefer bounded per-key `LIMIT` / PHP cap-per-group over sorting all rows for a large `IN` list.
- Do not load multi-thousand JSON “related id” blobs unless sliced in SQL/app first.
- Cards: poster/thumb for video URLs; no viewer-specific fields in cached cards.

## Package code rules

- Bulk writes only (`write_chunk` / `delete_chunk`).
- Console commands stay registered outside `runningInConsole` (Livewire / HTTP Artisan::call).
- Support Illuminate ^10–^13; widen testbench/pest when bumping.
- Prefer README + tests over new markdown files unless asked.

## Common failures

| Symptom | Check |
|---|---|
| Empty / only trending | Jobs never run; watermark/backfill too short |
| No CoInteraction in explain | Neighbours empty or user’s items not in graph |
| Slow cold build | `source_limit`/`feed_size` too high; bad source SQL; SQLite |
| Same feed for all cohorts | Freshness weights too high |
| Livewire Ops / rebuild slow | Debounce live inputs; avoid rebuild every keystroke |
