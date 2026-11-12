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

Stress tests run in the normal suite; run them alone or larger with `RECOMMENDER_STRESS_SCALE=5 vendor/bin/pest --group=stress`.

## Pull requests

- One topic per pull request, with tests for behaviour changes.
- Titles follow [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, `chore:`).
- Add a line under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md), and a section in [UPGRADE.md](UPGRADE.md) if users must change anything.
- Support PHP 8.3+ and Laravel 11–13; keep the hot path to cache reads and bulk writes (see [AGENTS.md](AGENTS.md)).

Releases are described in [docs/operations.md](docs/operations.md#publishing-a-package-release).
