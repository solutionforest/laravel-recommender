# Agent guidelines — solutionforest/laravel-recommender

Human docs live under [`docs/`](docs/). Prefer linking there over pasting walls of text into chat.

| Doc | Use for |
|---|---|
| [docs/tutorial.md](docs/tutorial.md) | Scaffold checklist |
| [docs/getting-started.md](docs/getting-started.md) | First-time wiring (slower) |
| [docs/how-it-works.md](docs/how-it-works.md) | Mental model |
| [docs/tuning.md](docs/tuning.md) | Weights / lessons |
| [docs/reference.md](docs/reference.md) | API / config / jobs |
| [docs/operations.md](docs/operations.md) | Go-live / FAQ / scale |
| [docs/maths.md](docs/maths.md) | How we score (formulas) |

## Mental model

- Never copies raw events; reads app tables via `InteractionSource`.
- Own tables: `rec_item_scores`, `rec_item_neighbours`, `rec_state` only.
- Hot path: cache list + seen-set + cards.
- Maths: item–item cosine CF, half-life `hot` via log-sum-exp, Wilson, blend + exploration.
- Lab patterns: `examples/blog/` (`InfiniteScrollFeed`, sponsored source+booster, poster URLs). Prefer personalisation over latest-first.

## First-time setup (do not skip)

1. Migrate.
2. `recommender:make-feed {name}` (scaffolds 3 classes + config) — then adjust table/column names.
3. `recommender:update-scores {feed}` then `recommender:build-neighbours {feed}`.
4. `recommender:explain {feed} {activeUserId}` — personal sources should appear.
5. Schedule scores / neighbours / prune; cache with locks (`redis` or `database`+`cache_locks`) + `recommender` queue in prod.

Historical imports: widen `scores_backfill_days` or clear watermark + scores, then rebuild neighbours.

## Fine-tuning

- Weights relative; `exploration_ratio` is slot interleave.
- Segments: personal high, Fresh\* low (~0.05–0.10).
- After weight changes: `forgetUser` / `forgetTrending`.
- Tune with 2–3 real users + `explain`, one knob at a time.

## Implementing sources

- Guests: `$context->userId` may be `null`.
- Indexed queries + `LIMIT`; avoid huge `IN (subquery)`.
- Cards: no viewer-specific fields; poster for video URLs.

## Package code rules

- Bulk writes only (`write_chunk` / `delete_chunk`).
- Console commands registered outside CLI (Livewire Artisan::call).
- Support PHP ^8.3 and Illuminate ^11–^13.
- Keep **README.md thin**; put depth in `docs/`.

## Common failures

| Symptom | Check |
|---|---|
| Empty / only trending | Jobs never run; backfill too short |
| No CoInteraction in explain | Neighbours empty |
| Slow cold build | `source_limit` / bad SQL / SQLite |
| Same feed for all cohorts | Freshness too high |
| Livewire Ops slow | Debounce live inputs |
