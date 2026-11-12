# Changelog

All notable changes to this package are documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.5.1] — 2026-11-12

Docs only.

### Removed
- `docs/improvements.md` (the improvement proposals page): all items shipped in 0.2–0.5, so it is removed along with its README link. Open ideas stay in [operations → roadmap](docs/operations.md#roadmap--open-ideas)

## [0.5.0] — 2026-11-12

Even less setup: one command from `composer require` to a working feed, and sensible weights in one line.

### Added
- `php artisan recommender:bootstrap {feed}` — migrate → `make-feed` → first `update-scores` / `build-neighbours` → `doctor` → prints the route, dashboard and schedule lines; non-zero exit when a check fails
- **Named presets**: `'preset' => 'catalog' | 'social' | 'events'` sets sources, boosters, event weights, half-life and exploration; order defaults < `defaults` < preset < feed; add your own under `presets`; unknown names fail with the available list
- `make-feed` writes the matching preset (blog / reviews → social, products → catalog, events → events)

### Changed
- Dashboard uses the project logo and its green palette (light and dark)

### Fixed
- `make-feed` no longer mistakes a trailing `// ['shop' => …]` comment in the config for an existing feed

## [0.4.0] — 2026-11-12

Better signals: count what users were actually shown, and see whether an item is getting better or worse. **Run `php artisan migrate`.**

### Added
- **Impressions** (opt-in `'impressions' => true`): every item served in a page is counted (lifetime + per day) with atomic bulk increments; `stats()` and `QualityBooster` then rate items by positive ÷ impressions
- **Quality over time**: `rec_item_daily` keeps per-day positive / exposure / impressions; `Recommender::stats($feed, $items, days: 7)` and `recommendationStats($feed, 7)`; the dashboard's trending table shows 7-day quality with ▲ / ▼ against 30 days
- Migration `add_impressions_and_daily_scores` (`impressions`, `last_shown_at` columns and the `rec_item_daily` table)

### Changed
- `rebuild-scores` resets event-derived scores instead of deleting rows, so impressions survive a rebuild
- `prune` also removes daily rows past retention and keeps items that are still being shown

## [0.3.0] — 2026-11-12

Never run out of items: feeds with a `model` keep scrolling into the catalog, and clients can follow a cursor.

### Added
- **Catalog tail**: after ranked and trending items, pages continue with the newest items the model's `recommendable` scope allows (signed-in users skip their seen-set; guests page by offset), until the catalog is exhausted. `origin: 'tail'` for such pages. Config `tail` (default `true`) and `tail_order`
- **Cursor pagination**: `FeedPage::nextCursor()` / `toCursorArray()`; `?cursor=` is accepted wherever `?page=` is, and returning a page for a cursor request renders Laravel's cursor-paginator JSON. Paginator JSON also includes `next_cursor`

### Removed
- `examples/blog/src/Support/InfiniteScrollFeed` — replaced by the built-in tail and cursor

## [0.2.0] — 2026-11-12

Finishes the improvements list: safe score rebuilds, an explain that shows the real order, a config-only `make-feed`, per-item quality stats. Upgrading: see [UPGRADE.md](UPGRADE.md) (nothing to change in apps).

### Added
- `recommender:rebuild-scores {feed} --days= --dry-run --force` — recompute one feed's scores over a window; confirmation, never double-counts, an interrupted rebuild blocks `update-scores` for that feed until rerun (shown by `doctor` / dashboard)
- `explain()` rows include `final` (position after exploration and diversity), `cached` (position in the served list) and `exploration`; shown by `recommender:explain` and the dashboard
- `Recommender::stats($feed, $items)` and `$model->recommendationStats($feed)`: positive / exposure counts, rate, confidence-adjusted `quality` (Wilson) and relative `popularity` per item; quality column in the dashboard's trending table
- Docs: [hiding an item](docs/reference.md#hiding-an-item-visibility-changes) recipe, covered by a test; all guides, the agent skill and screenshots refreshed for 0.2

### Changed
- `recommender:make-feed` registers a **config-only feed** (model + interaction tables) and prints the model methods to add; `--classes` generates the previous `InteractionSource` / `ItemFilter` / `CardTransformer` classes
- `update-scores` reports a failing feed and continues with the others (exit code 1)

### Fixed
- Item ids may contain commas: packed cache lists percent-encode `,` and `%` inside ids (plain ids keep the compact format)

## [0.1.1] — 2026-11-12

Examples now use the 0.1.0 config-only API. No package code changes.

### Changed
- Every example (`blog`, `products`, `events`, `events-advanced`, `reviews`) is now a config-only feed: `model` + `interactions` in the config, `scopeRecommendable()` / `toRecommendationCard()` / `Recommendable` on the model, one-line `recommendations()` controllers. Their `InteractionSource`, `ItemFilter`, `CardTransformer` and `DiversityGroups` classes are removed; `events-advanced` keeps a custom `CardTransformer` to show when a class is still the right tool
- Example diversity keys are model attributes (`author_id`, `seller_id`, `host_id`, `district_id`, `type_id`, `shop_id`, `user_id`)
- `events-advanced`: `Event::scopeRecommendable()` takes the ranking `Context` (was `$now, $userId`)
- PHPStan (level 8) now analyses every example; each example test also runs `recommender:doctor`

## [0.1.0] — 2026-11-12

First minor release: a feed is now **configuration only** — tell the package what you recommend (a model) and where the interactions are (tables) — plus a dashboard to watch it. Upgrading from 0.0.x: see [UPGRADE.md](UPGRADE.md).

### Added
- **Config-only feeds**: set `model` + `interactions` (`[table => event]`) instead of writing `InteractionSource`, `ItemFilter`, `CardTransformer` and `DiversityGroups` classes. `Interactions\DatabaseInteractions` reads the tables (integer, UUID and ULID ids; per-table column overrides; `history_events` for neighbours). `Eloquent\ModelFilter` / `ModelCards` / `ModelGroups` use the model's optional `scopeRecommendable()` / `toRecommendationCard()` and attribute-based diversity keys. See [`examples/simple`](examples/simple/)
- `Eloquent\Recommendable` model trait and `Recommender::forgetModel()` drop cached cards on save / delete
- `recommendations($feed, $user)` helper
- `FeedPage` is `Responsable` / `JsonSerializable`: return it from a route to get paginator JSON
- **Dashboard** at `/recommender` (Blade, no JS build): pipeline status, health cards, trending, checks and *Explain a user*; light / dark theme; `viewRecommender` gate, open in `local`; `recommender.dashboard.*` config. Guide with screenshots: [docs/dashboard.md](docs/dashboard.md)
- `recommender:doctor` — configuration, table, column and pipeline checks; non-zero exit code on failure
- `explain()` rows include `contributions`: each source / booster's share of the score (they add up to `score`)
- Stress tests for data volume (100k events) and interaction frequency (`--group=stress`, `RECOMMENDER_STRESS_SCALE`)
- GitHub Actions [`release.yml`](.github/workflows/release.yml): push a `v*.*.*` tag → GitHub Release with notes from this file
- `UPGRADE.md`, `CONTRIBUTING.md`, `SECURITY.md`, `.gitattributes` (tests, CI and artwork are left out of Composer installs)

### Changed
- `page()`, `build()`, `warm()`, `forgetUser()` and `explain()` accept the user model as well as an id; a model is added as the `user` attribute
- `page()`'s `$page` defaults to the request's `?page=` (was `1`); `toPaginator()`'s `path` defaults to the current URL (was `/`)
- Default `sources` are `CoInteractionSource` 0.6 + `TrendingSource` 0.4 (was empty, i.e. trending only). Feeds that set `sources` are unaffected
- `illuminate/http`, `illuminate/routing` and `illuminate/view` are explicit dependencies
- `recommender:status` reads its metrics from the shared `Dashboard\Health`
- Docs: the tutorial and getting started lead with the config-only setup; `make-feed` is option B

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

[Unreleased]: https://github.com/solutionforest/laravel-recommender/compare/v0.5.1...HEAD
[0.5.1]: https://github.com/solutionforest/laravel-recommender/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/solutionforest/laravel-recommender/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.0.15...v0.1.0
[0.0.15]: https://github.com/solutionforest/laravel-recommender/compare/v0.0.14...v0.0.15
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
