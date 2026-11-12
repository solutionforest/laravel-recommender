# Changelog

All notable changes to this package are documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Config-only feeds**: set `model` + `interactions` (`[table => event]`) and skip the `InteractionSource`, `ItemFilter`, `CardTransformer` and `DiversityGroups` classes. New `Interactions\DatabaseInteractions` reads the tables (integer, UUID and ULID ids; per-table column overrides; `history_events` for neighbours). `Eloquent\ModelFilter` / `ModelCards` / `ModelGroups` use the model's optional `scopeRecommendable()` / `toRecommendationCard()` and attribute-based diversity keys. See [`examples/simple`](examples/simple/)
- `Eloquent\Recommendable` model trait + `Recommender::forgetModel()` drop cached cards on save / delete
- `recommendations($feed, $user)` helper
- `FeedPage` is `Responsable` / `JsonSerializable`; return it from a route to get paginator JSON
- GitHub Actions [`release.yml`](.github/workflows/release.yml) — push `v*.*.*` tag → Release with notes from `CHANGELOG.md`

- Dashboard at `/recommender` (Blade, no JS build): health, checks, trending, explain; `viewRecommender` gate, open in `local`; `recommender.dashboard.*` config. Guide with screenshots: [docs/dashboard.md](docs/dashboard.md)
- `recommender:doctor` — configuration / table / column / pipeline checks with a non-zero exit code on failure (`docs/improvements.md` #1)
- `tests/Feature/StressTest.php` — data-volume and interaction-frequency stress tests (`--group=stress`, `RECOMMENDER_STRESS_SCALE`)

### Changed
- `page()` / `build()` / `warm()` / `forgetUser()` / `explain()` accept the user model as well as an id; a model is added as the `user` attribute
- `page()`'s `$page` defaults to the request's `?page=` (was `1`); `toPaginator()`'s `path` defaults to the current URL (was `/`)
- Default `sources` are `CoInteractionSource` 0.6 + `TrendingSource` 0.4 (was empty, i.e. trending only). Feeds that set `sources` are unaffected
- `illuminate/http`, `illuminate/routing` and `illuminate/view` are now explicit dependencies
- `recommender:status` reads its metrics from the shared `Dashboard\Health`

### Fixed
- `recommender:make-feed` no longer mistakes commented-out config examples for an existing feed

## [0.0.15] — 2026-11-12

### Docs
- Explain `Context` (ranking bag vs HTTP request) in [reference.md](docs/reference.md), getting-started, examples README, and class docblocks
- README PHPStan level 8 badge

### Fixed
- PHPStan: `InfiniteScrollFeed` Post import after example folder move, `MakeFeedCommand::resolvePreset()` return type, `Math::itemId()` object id access

## [0.0.14] — 2026-11-12

### Added
- `examples/events-advanced` — district, host party, event type, comments on top of events; `JoinPreferences` + Pest tests
- Scoring helpers: `Math::fromPosition`, `fromAffinity`, `fromAge`, `normalizeMax`, `scoreInOrder`, `preferRanked` ([docs/maths.md](docs/maths.md) §5)

### Changed
- All examples use categorized folders: `Models/` · `Interactions/` · `Sources/` · `Boosters/` · `Filters/` · `Cards/` · `Groups/` · `Http/` · `Support/`

## [0.0.13] — 2026-11-12

### Changed
- `recommender:make-feed` interactive UI uses [Laravel Prompts](https://laravel.com/docs/prompts) (`select` / `text` / `confirm` / `table` / `intro`)

### Added
- Composer require `laravel/prompts` (^0.1.25|^0.2|^0.3)

## [0.0.12] — 2026-11-12

### Added
- `examples/events` — meetup listing with `view` / `like` / `join` + Pest test
- `make-feed --preset=events` defaults (event_joins → `join`)

## [0.0.11] — 2026-11-12

### Added
- Interactive `recommender:make-feed` wizard (presets blog/products/reviews/custom, optional third event, confirm summary)
- Flags: `--preset`, `--purchases`, `--purchase-event`, `--owner-column`, …

### Changed
- InteractionSource stub supports N event tables via `TABLES` entries

## [0.0.10] — 2026-11-12

### Added
- `examples/products` — catalog feed with **three events** (`view` / `like` / `purchase`) + Pest test
- `examples/reviews` — shop reviews + `PetTypeMatchSource` custom rule + Pest test
- `examples/README.md` — index of all samples

## [0.0.9] — 2026-11-12

### Changed
- Docs: clarify interactions are **not only likes/views** — any event names (`share`, `bookmark`, …) via `events` + `InteractionSource`

## [0.0.8] — 2026-11-12

### Added
- Cursor skill: `skills/laravel-recommender/SKILL.md` for consuming apps
- README section: For AI agents / Cursor
- Expanded `AGENTS.md` for app wiring + package maintainers

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

[Unreleased]: https://github.com/solutionforest/laravel-recommender/compare/v0.0.15...HEAD
[0.0.15]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.15
[0.0.14]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.14
[0.0.13]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.13
[0.0.12]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.12
[0.0.11]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.11
[0.0.10]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.10
[0.0.9]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.9
[0.0.8]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.8
[0.0.7]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.7
[0.0.6]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.6
[0.0.5]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.5
[0.0.4]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.4
[0.0.3]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.3
[0.0.2]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.2
[0.0.1]: https://github.com/solutionforest/laravel-recommender/releases/tag/v0.0.1
