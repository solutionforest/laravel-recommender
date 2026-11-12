# Changelog

All notable changes to this package are documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.0.7] — 2026-11-12

### Changed
- README / Packagist: position as a **simple recommendation engine** for Laravel
- Composer keywords + GitHub topics: `recommendation-engine`, `recommender-system`, `collaborative-filtering`, …

## [0.0.6] — 2026-11-12

### Added
- Tests: cache stores **array / file / database / redis** provide locks and serve `page()` (redis skipped if unavailable)

### Changed
- Docs/config: cache locks are any `LockProvider` store (redis recommended; database OK on L11–13 with `cache_locks`); `RECOMMENDER_CACHE_STORE` empty → null

## [0.0.5] — 2026-11-12

### Added
- `docs/tutorial.md` — step-by-step scaffold checklist (`make-feed` → edit → cook → schedule)

### Changed
- README “Start here” points at the scaffold tutorial first
- Docs: English-only wording

## [0.0.4] — 2026-11-12

### Added
- `php artisan recommender:make-feed {name}` — scaffold InteractionSource, ItemFilter, CardTransformer + register feed config

## [0.0.3] — 2026-11-12

### Added
- README: **How to adjust the ratio** (views / likes / sponsors)
- README: PHP 8.3+ and Laravel 11–13 badges under the logo
- Tests: event weights flip trending order; SponsoredShop source+booster; UnlockedBuildLock when cache has no LockProvider

## [0.0.2] — 2026-11-12

### Changed
- Require **PHP 8.3+** and **Laravel 11–13** (dropped PHP 8.1/8.2 and Laravel 10)

## [0.0.1] — 2026-11-12

### Added
- Self-hosted recommendation feeds (initial public release)
- `Recommender::page()` with seen-set paging and guest trending fallback
- `FeedPage::toPaginator()` / `toPaginatorArray()` for Laravel paginator JSON
- Jobs / commands: `update-scores`, `build-neighbours`, `prune`, `explain`, `status`
- Built-in `CoInteractionSource`, `TrendingSource`, `QualityBooster`
- Blog example under `examples/blog`
- Docs: getting started, how we recommend, how we score, tuning, ops

[Unreleased]: https://github.com/solutionforest/laravel-recommender/compare/v0.0.7...HEAD
[0.0.7]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.7
[0.0.6]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.6
[0.0.5]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.5
[0.0.4]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.4
[0.0.3]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.3
[0.0.2]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.2
[0.0.1]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.1
