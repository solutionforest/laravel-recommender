# Agent guidelines — solutionforest/laravel-recommender

**Simple recommendation engine for Laravel** (PHP 8.3+ · Laravel 11–13). Self-hosted for-you feeds: collaborative filtering + trending + custom sources — not Personalize / not `ORDER BY RAND()`.

Human docs live under [`docs/`](docs/). Prefer linking there over pasting walls of text into chat.

| Doc | Use for |
|---|---|
| [docs/tutorial.md](docs/tutorial.md) | Scaffold checklist (`make-feed`) |
| [docs/getting-started.md](docs/getting-started.md) | First-time wiring (slower) |
| [docs/how-it-works.md](docs/how-it-works.md) | Mental model |
| [docs/tuning.md](docs/tuning.md) | Weights / lessons |
| [docs/reference.md](docs/reference.md) | API / config / jobs / cache locks |
| [docs/operations.md](docs/operations.md) | Go-live / FAQ / scale |
| [docs/maths.md](docs/maths.md) | How we score (formulas) |
| [skills/laravel-recommender/SKILL.md](skills/laravel-recommender/SKILL.md) | Cursor skill for **consuming apps** |
| [examples/](examples/) | Samples (`Models/` · `Sources/` · `Boosters/` · …): `blog`, `products`, `events`, `events-advanced`, `reviews` |

---

## When working in a **consuming Laravel app**

1. `composer require solutionforest/laravel-recommender` → `php artisan migrate`
2. `php artisan recommender:make-feed` (interactive wizard) or `… posts -n` — then tweak scaffolded classes if needed
3. `recommender:update-scores` → `build-neighbours` → `explain {feed} {userId}`
4. Serve with `Recommender::page()` → `toPaginator()` / `toPaginatorArray()`
5. Schedule scores / neighbours / prune; cache with locks (`redis` or `database`+`cache_locks`)

Copy the Cursor skill into the app so agents auto-load it:

```bash
mkdir -p .cursor/skills
cp -R vendor/solutionforest/laravel-recommender/skills/laravel-recommender .cursor/skills/
```

Do **not** invent an external ML service for this package’s job. Prefer package sources + app-specific `CandidateSource` / `Booster` classes.

### Config knobs (relative weights)

- `events` map is **any** event names (`view` / `like` / `share` / …) → trending hot; `positive_events` / `exposure_events` for quality  

- `sources` → blend pool · `boosters` → re-rank in-pool · `exploration_ratio` → reserved explore slots  
- After changes: `Recommender::forgetUser` / `forgetTrending`

Historical imports: set `scores_backfill_days` before the first score run. For an existing feed, follow the feed-scoped rebuild procedure in `docs/operations.md`.

---

## Mental model (package)

- Never copies raw events; reads app tables via `InteractionSource`.
- Own tables: `rec_item_scores`, `rec_item_neighbours`, `rec_state` only.
- Hot path: cache list + seen-set + cards.
- Maths: item–item cosine CF, half-life `hot` via log-sum-exp, Wilson, blend + exploration.
- Prefer personalisation over latest-first.

## Implementing sources (app or package examples)

- `Context` = ranking bag for sources/filters/boosters (not HTTP). `$context->userId` from `page($feed, $userId)`; `null` = guest. See `docs/reference.md` § What is Context?
- Indexed queries + `LIMIT`; avoid huge `IN (subquery)`.
- Cards: no viewer-specific fields; poster/thumbnail for video URLs.

## Package maintainers

- Bulk writes only (`write_chunk` / `delete_chunk`).
- Console commands registered outside CLI (Livewire `Artisan::call`).
- Support PHP ^8.3 and Illuminate ^11–^13.
- Keep **README.md thin**; put depth in `docs/`. Update [CHANGELOG.md](CHANGELOG.md) on release (`## [x.y.z]`), then `git tag -a vX.Y.Z` + `git push origin vX.Y.Z` — [release.yml](.github/workflows/release.yml) publishes the GitHub Release from that changelog section.

## Common failures

| Symptom | Check |
|---|---|
| Empty / only trending | Jobs never run; backfill too short |
| No CoInteraction in explain | Neighbours empty |
| Slow cold build | `source_limit` / bad SQL / SQLite |
| Same feed for all cohorts | Freshness / explore weight too high |
| Livewire Ops slow | Debounce live inputs |
| `locks: no` in status | Cache store lacks LockProvider — use redis or database + `cache_locks` |
