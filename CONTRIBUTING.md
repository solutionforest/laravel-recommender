# Contributing

Thanks for helping. Issues and pull requests are welcome on [GitHub](https://github.com/solutionforest/laravel-recommender).

## Development

```bash
composer install
composer test       # Pest (SQLite)
composer analyse    # PHPStan level 8
```

Run against MySQL / MariaDB like CI does:

```bash
RECOMMENDER_TEST_DB=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rec_test DB_USERNAME=root vendor/bin/pest
```

Stress tests run in the normal suite; run them alone or larger with `RECOMMENDER_STRESS_SCALE=5 vendor/bin/pest --group=stress`. Add `RECOMMENDER_PERF=1` to print the measured times, queries and memory. Update [docs/performance.md](docs/performance.md) when they change noticeably.

Opt-in, on MySQL / MariaDB:

```bash
RECOMMENDER_HEAVY=1 RECOMMENDER_TEST_DB=mysql … vendor/bin/pest --group=heavy              # 1M events, 500k users (~3 min)
RECOMMENDER_CONCURRENCY=1 RECOMMENDER_TEST_DB=mysql … vendor/bin/pest --group=concurrency  # parallel processes, needs pcntl
```

## Where things live

`Recommender` (the facade) is a thin entry point: it resolves the feed and the viewer, then hands off to one small class per job.

| Class | Job |
|---|---|
| `Feed\FeedRegistry` | Config → `FeedDefinition` (package defaults < app `defaults` < preset < feed), the on/off switch |
| `Feed\FeedServer` | Serve one page: personal list → trending → catalog tail, or the shared list for guests |
| `Feed\ListBuilder` | Build and cache ranked lists (per user under a lock, and the shared trending list) |
| `Feed\Variants` | Which cache copy a request reads (`list_vary_by`, `seen_vary_by`, `cards_vary_by`) |
| `Feed\ImpressionRecorder` | Count served items (after the response in HTTP) |
| `Feed\FeedCache` | Every cache key the package writes |
| `Ranking\Ranker` | Sources → filter → blend → boosters → exploration → diversity, and `explain()` |
| `Insights\ItemStats` | "How good is this item?" (`stats()`) |
| `Feed\SimilarItems` | "More like this item" (`similar()`): neighbours → filter → cards, cached per item and neighbour version |
| `Storage\ScoreStore` | The `rec_*` tables |

Extend through the contracts in `src/Contracts` (sources, boosters, filters, cards, groups, interactions) and feed config. The classes above are `final` and internal: their signatures can change in a minor release.

## Pull requests

- One topic per pull request, with tests for behaviour changes.
- Titles follow [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, `chore:`).
- Add a line under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md), and a section in [UPGRADE.md](UPGRADE.md) if users must change anything.
- Support PHP 8.3+ and Laravel 10–13; keep the hot path to cache reads and bulk writes (see [AGENTS.md](AGENTS.md)).

Releases are described in [docs/operations.md](docs/operations.md#publishing-a-package-release).
