# Changelog

All notable changes to this package are documented here.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.10.8] — 2026-11-14

Security checks in CI. No behaviour changes.

### Added
- **Security workflow** (every PR, `main`, weekly): `composer audit` of the runtime dependencies for Laravel 10–13 (Laravel 10 and 11 report-only: both are end of life and have advisories that will not be fixed, e.g. CVE-2026-48019), and **Psalm taint analysis** of `src/` that fails on any `Tainted*` finding. See [SECURITY.md](SECURITY.md#automated-checks)
- Dependabot updates for the CI actions; on the repository: Dependabot alerts and security updates, secret scanning with push protection

### Changed
- Feed names are validated in one place (`FeedDefinition::validName()`), which the taint analysis recognises. Its first run flagged the dashboard's `?feed=` reaching a query: not exploitable (the value must name a configured feed, names are limited to `[A-Za-z0-9_-.]`, and it is a bound parameter), now provably so
- CI: `actions/checkout@v5` (v4 ran on a deprecated Node version)
- README supported-versions table: Laravel 11 no longer gets security fixes from Laravel (since March 2026); Laravel 12 needs 12.60+ for Laravel's own fixes

## [0.10.7] — 2026-11-14

PHPStan level 10 (max). No feature changes.

### Changed
- **Static analysis at PHPStan level 10** for `src/` and the examples (was 8): every value read from config, the cache or a database row is checked or converted to its type before use, through the new `Support\Value` helper (`int`, `float`, `string`, `id`, `map`; same results as a PHP cast for the values these sources hold). No ignores, no baseline
- A feed's `filter`, `cards`, `groups` or `interactions` class that does not implement its contract now fails with a clear `InvalidArgumentException` naming the feed and key (before: a `TypeError`)
- Cache entries that are not what the package wrote (e.g. edited by hand) are ignored instead of failing: non-id entries in cached lists are dropped, and a list entry whose `ids` is not an array is treated as missing
- `FeedDefinition::list()` returns only non-empty strings (`list<string>`)

Stress numbers are unchanged within run-to-run noise (`update-scores` ~91k events/s; `similar()` cold 3 queries / warm 0).

## [0.10.6] — 2026-11-14

Supported versions in one table; no package code changes.

### Docs
- README: **Supported versions** table (Laravel 10–13 × PHP 8.3–8.5), showing what CI tests and the Laravel 10 end date
- Release steps: `main` is protected (all CI checks must pass before merge, no direct pushes); releases go through a pull request, then the merge commit is tagged

### Changed
- CI: the full suite also runs on PHP 8.5 with Laravel 11 and 12

## [0.10.5] — 2026-11-14

Correct scores outside UTC, and safer impressions. **If your app timezone is not UTC, run `recommender:rebuild-scores` after upgrading** (see UPGRADE).

### Fixed
- **Scores in apps whose timezone is not UTC (Laravel 11–13).** `update-scores` read its stored watermark back in UTC (Carbon 3) while interaction tables hold app-local times. East of UTC (e.g. `Asia/Hong_Kong`) each run re-read the last hours and counted events again; west of UTC (e.g. `America/New_York`) new events could be skipped. The watermark is now read in the app timezone (also in the dashboard and `rebuild-scores`). Laravel 10 (Carbon 2) was not affected. Present since 0.0.1
- **Redis cache store:** the cached neighbour version came back as a string, so it was never reused and every `similar()` call ran an extra query. Found by the new Redis CI job

### Security
- **Guest pages no longer count impressions by default** (only with `'impressions' => true`). Guests have no seen-set, so every reload counted again and anonymous traffic could push an item's quality down, which `rebuild-scores` cannot undo. Opt back in with `'impressions_guests' => true`, behind a rate limit
- **`cards_vary_by` variants are capped per card** (`card_variants_max`, default 20, oldest dropped), so unvalidated attribute values cannot grow one cache entry without limit

### Changed
- CI: PHP 8.5 with Laravel 13; Laravel 10 on PHP 8.5 is an install + class-loading check, because Laravel 10's test tools (Testbench 8 / Pest 2) do not install on PHP 8.5; and a job that runs the whole suite on a real Redis cache store (`RECOMMENDER_TEST_CACHE=redis`)
- README: Laravel 10 support ends with the first release after 31 March 2027

## [0.10.4] — 2026-11-14

Docs checked against the code. No package code changes.

### Docs
- **Tuning:** removed content from an app-specific feed (pet / shop classes that do not exist in the package, "lessons from a real inspiration feed", video posters). Recipes now use package classes plus clearly marked classes you write; new "Common pitfalls" table; recipes in A–D order
- **Maths:** neighbour cut-offs (`neighbours_min_co_count`, `neighbours_max`, `history_per_user`), what `rank(i)` means, booster term in the blend, the exact exploration interleave rule, relaxed diversity caps, `Math::fromAge` is `exp(−age / halfLife)` (≈ 0.37 after `halfLife`, not a true half-life)
- **Reference:** `origin` can also be `'tail'`; queued `warm()` carries the attributes you pass (not the user model); impression writes described correctly (two bulk updates, more for new rows); ~270 pages/s matches the measured run
- **Operations:** generic FAQ and SQL example (no app-specific wording); current version in the release example
- **Performance:** cold `similar()` is 3 queries in the summary table too; page title
- **Tutorial / how it works / CONTRIBUTING / AGENTS / agent skill:** `origin` values, `FeedPage` fields, storage classes, Laravel ^10–^13, `locks: fallback` wording, app-specific lines removed
- **Config comments:** `cache_store` null means Redis when reachable, else `CACHE_STORE`; links point to `docs/tuning.md` and `docs/operations.md`

### Changed
- `examples/blog`: `SponsoredShopSource` / `SponsoredShopBooster` renamed to `SponsoredAuthorSource` / `SponsoredAuthorBooster`; they read only `sponsored_author_ids` (the `sponsored_shop_ids` fallback is gone)

## [0.10.3] — 2026-11-13

Security fix for `similar()`. Upgrade if your `recommendable` scope or `filter` reads an attribute (tenant, region, locale).

### Security
- **`similar()` could show items the `recommendable` scope hides** (since 0.10.0). For a feed with a `model` and no custom `filter`, the scope was only applied when cards rendered, and cards come from the shared card cache. So an item whose card was already cached (e.g. by another tenant) passed for every `list_vary_by` value: with a tenant-scoped feed, tenant 1 could see tenant 2's items in a related block. The filter now always runs when the list is built (one more query cold; warm reads still use no query). `page()` was not affected. Workaround on 0.10.0–0.10.2: add the attribute to `cards_vary_by`
- `similar()` keeps an empty result (unknown id, or an item without neighbours) for 60 s instead of `similar_ttl`, so made-up ids cannot fill the cache store for an hour

### Docs
- `SECURITY.md`: 0.10.x is the supported line
- README: Laravel 10 support is best-effort (Laravel 10 no longer gets security fixes)
- Performance: similar-items numbers re-measured (cold 3 queries)

### Changed
- `composer.json` branch alias: `dev-main` is `0.10.x-dev`

## [0.10.2] — 2026-11-13

Docs catch up with 0.9 and 0.10.

### Docs
- Removed the "Roadmap / open ideas" section from operations: everything it listed has shipped
- Maths: `jitter` formula, and how similar items use the neighbours
- How it works and README: `jitter` step in the feed pipeline
- Laravel 10–13 everywhere it still said 11–13 (README, CONTRIBUTING, agent skill, config comments, cache reference, CI note in performance)

## [0.10.1] — 2026-11-13

Similar items after a rebuild, and the docs.

### Fixed
- `similar()` could return an empty list right after `build-neighbours`: the cached neighbour version was dropped only after the old rows were deleted, and never on servers that do not share the builder's cache store. The version is now dropped as soon as the new one is active, and an empty list that was just built rechecks the active version once
- `NeighbourStore::activate()` takes an optional callback that runs after the switch and before old rows are deleted

### Docs
- Similar items in the agent skill (`skills/laravel-recommender/SKILL.md`), `AGENTS.md`, the tutorial, how it works, operations (storage table, FAQ, roadmap), the blog example and the class map in CONTRIBUTING
- Reference: `similar()` keeps serving while the package is switched off; pass a loaded model or a validated id (each id gets its own cache entry)
- UPGRADE entries for 0.10.0 and 0.10.1

## [0.10.0] — 2026-11-13

Similar items.

### Added
- **Similar items**: `$post->similar(6)` / `Recommender::similar('posts', $post, 6)` return cards of the items most often liked together with this one (the neighbours `build-neighbours` already computes), e.g. for a related-posts block. Viewer-independent and cached per item (`similar_ttl`, keyed by neighbour version so a rebuild switches over at once); nothing new is stored. Warm calls are cache reads only, cold calls one neighbours row + one card query; the stress test shows the same cost at 2,000 and 10,000 users
- `NeighbourStore::neighboursOf()` accepts the active version when the caller already has it

## [0.9.0] — 2026-11-13

Laravel 10, and random-order feeds.

### Added
- **Laravel 10 support** (PHP 8.3+ unchanged). No runtime code changes were needed; CI now runs Laravel 10 on PHP 8.3 and 8.4.
- **`jitter`** feed option (0 … 1): mixes randomness into the ranking once per list build — `(1 − j) · score / max + j · U(0,1)`. `1` gives a uniform random order of the candidates (a classic "random" feed without `ORDER BY RAND()`); the order stays stable while the list is cached, so pages never repeat. Never applied to the shared trending list. Default `0` (no change).

### Changed
- Docs: with `jitter` set, *Explain a user* shows rows ranked before the shuffle; use *Served* for the real order
- Example models declare casts with the `$casts` property instead of the Laravel 11+ `casts()` method, so the examples run on Laravel 10–13.

## [0.8.5] — 2026-11-13

Measured, and written down.

### Added
- **[docs/performance.md](docs/performance.md)**: every performance test with measured results and how to reproduce them:
  - page latency (warm 0.2 ms, cold 5–8 ms p50);
  - score throughput (64k–165k events/s);
  - 1M events from 500k users in 10 minutes (exact counts, 0 errors);
  - concurrency up to 48 processes on the database cache (0 deadlocks);
  - every-minute streams and bursts, Octane.
- `RECOMMENDER_PERF=1` makes the stress tests print what they measure; the heavy test also reports page p50 / p95

## [0.8.4] — 2026-11-13

Ready for Laravel Octane.

### Fixed
- **Long-lived workers (Laravel Octane, queue workers):** `Recommender` is now a scoped binding instead of a singleton, so a feed config changed at runtime (e.g. per tenant) is read in that request and does not stick for the rest of the worker's life

### Added
- `tests/Feature/OctaneTest.php`: serves pages through an Octane-style worker loop (no repeats across requests, impressions written on terminate, config isolated per request)

## [0.8.3] — 2026-11-13

Cleaner inside; no behaviour change.

### Changed
- `Recommender` is now a thin entry point (≈300 lines, was ≈1,000) that hands off to small single-job classes: `FeedRegistry`, `FeedServer`, `ListBuilder`, `Variants`, `ImpressionRecorder`, `Ranker`, `ItemStats`. Public API and facade unchanged. Map in CONTRIBUTING.md
- The concurrency test drops leftover `cache` tables before it starts

## [0.8.2] — 2026-11-13

Safe under heavy load on the database cache.

### Fixed
- **Deadlocks on impression writes under concurrency.** `INSERT IGNORE` on existing rows took shared locks that concurrent requests then upgraded → MySQL deadlocks (213 in 1,800 pages in the new concurrency test). Now: increment existing rows first in sorted key order, insert only missing rows, one statement per commit, retry on deadlock. Score merges write in the same order. Same test: 0 deadlocks at 24 processes × 300 pages
- A failed seen-list or card cache write no longer fails the page; it is logged and the feed is served

### Added
- **On / off switch**: `RECOMMENDER_ENABLED=false` switches the whole package off (trending only, no writes, `warm()` does nothing, scheduled jobs pause); `'enabled' => false` on a feed does the same for one feed (scheduled runs skip it). `Recommender::enabled($feed)`; `doctor` / dashboard show it
- `recommender:prune` deletes the package's expired rows from a **database** cache store (Laravel only removes an expired row when that key is read again)
- `doctor` / dashboard name the cache store in use, with a hint for the database store
- Opt-in `tests/Feature/ConcurrencyTest.php` (`--group=concurrency`, MySQL + pcntl)
- Docs: cache store choice, database-cache behaviour under load, and the measured results (reference, operations, CONTRIBUTING)

## [0.8.1] — 2026-11-13

Less to configure.

### Added
- **Automatic cache store**: without `RECOMMENDER_CACHE_STORE`, the package uses Redis when the app has a working Redis store, otherwise the app's default store (probe remembered for an hour; `recommender:status` / the dashboard show the choice)
- `RECOMMENDER_SEEN_MAX` and `RECOMMENDER_SEEN_TTL` env variables for the seen-list size and lifetime (defaults unchanged: 200 ids, 3 days)

## [0.8.0] — 2026-11-12

Impressions that mean "first shown".

### Changed
- **Impressions are counted once per user and item within the seen window** (`seen_max` items / `seen_ttl`), not on every serve: a refresh, a retry or the page-1 fallback that re-shows seen items no longer inflates them. Guests have no seen-set and still count once per page served. **Stored counts will grow more slowly than before and `rate` / `quality` rise accordingly** — see [UPGRADE.md](UPGRADE.md)
- Impressions are written **after the response** when serving HTTP (`'impressions_after_response' => false` keeps them inline). In the console (tests, jobs) they are written at once. A failed write is logged and never breaks serving

### Fixed
- The seen-set update is atomic where the cache store has locks: two parallel requests for one user (two tabs, a retry) no longer overwrite each other's additions, so users see fewer repeats. `FeedCache::markSeen()` now returns the ids that were new

## [0.7.1] — 2026-11-12

### Added
- `recordInteraction()` — `$post->recordInteraction($user, 'like', once: true)` or `Recommender::recordInteraction($feed, $item, $user, $event, …)` writes a row with the feed's own table and columns. Plain insert by default (every view kept; a missing or extra required column fails loudly); `once: true` is idempotent for like-style tables with a unique (user, item) index and returns `false` when it already exists; an event that maps to two tables throws unless you pass `table:`. The timestamp is set by the package; no events, no jobs. Unliking stays an app-side delete

## [0.7.0] — 2026-11-12

Context isolation and honest paging.

### Added
- `list_vary_by` (e.g. `['tenant']`): the ranked list, the shared trending list, the build lock and the background job are kept per attribute value, so a user switching between tenants (or guests of different tenants) never gets another tenant's items. `seen_vary_by` splits the seen-set; by default only `tenant` does, so `device` / `locale` keep "no repeats across devices". Feeds without `list_vary_by` use exactly the same cache keys as before
- `warm($feed, $user, attributes: [...])`, `forgetUser($feed, $user, $attributes)`, `forgetTrending($feed, $attributes)` and `explain($feed, $user, $limit, $attributes)` take the attributes of the variant they act on
- `FeedPage::toSimplePaginator()` and `FeedPage::$sequential`

### Changed
- **Signed-in feeds return Laravel's simple-paginator JSON** (`data`, `current_page`, `per_page`, `next_page_url`, `next_cursor`, `origin`, `has_more`) instead of the length-aware one: they are sequential (each request serves the next unseen batch), so the invented `total`, `last_page` and numbered `links` are gone. Guests keep the full paginator. `?page=` and `?cursor=` still work; `toPaginator()` still returns a `LengthAwarePaginator`. See [UPGRADE.md](UPGRADE.md)
- Docs say plainly that a refresh or retry advances a signed-in feed and that `next_cursor` is the way to continue

## [0.6.3] — 2026-11-12

Fixes from review.

### Fixed
- A failed shared trending build is no longer cached for `trending_ttl`: an exception is never cached and an empty list is kept for at most 15 seconds, so a recovered source shows up quickly (guests and the trending fallback no longer stay empty for ten minutes)
- `FeedPage::toPaginator()`, `toPaginatorArray()` and JSON encoding keep the request's query string (`?sort=…`) in their links, like returning the page from a route already did
- Code and docs agree on `seen_max` = 200; the impressions comment no longer promises "one bulk write per page"

## [0.6.2] — 2026-11-12

Small fixes and options from review.

### Added
- `FeedPage::models()` — the page as Eloquent models in ranked order (one query), e.g. `PostResource::collection(Post::recommendedFor($user)->models())`
- `cards_vary_by` (e.g. `['locale']`): cards are cached per attribute value; `toRecommendationCard(?Context $context)` receives the attributes; all variants of an item are forgotten together

### Changed
- Cursors carry the feed name; a cursor from another feed is ignored. Docs state that a cursor is "the next page for this user", not a snapshot. Older cursors keep working

### Fixed
- Zero-padded string ids (`"007"`, `"000123"`) are no longer turned into integers

## [0.6.1] — 2026-11-12

Docs and examples.

### Added
- [docs/advanced.md](docs/advanced.md) — advanced usage: own sources and boosters, extra `Context` attributes, replacing built-ins with classes, interaction-table variations, several feeds per model, A/B tests, custom presets, item stats, serving (API resources / Blade / Livewire / cursors), caching and invalidation, scheduling and queues, testing a feed (the testing example runs in the suite)

### Changed
- Examples use named presets (`catalog`, `social`, `events`) and drop keys the preset already sets; controllers are `Model::recommendedFor($request->user())`
- Docs refreshed for 0.6: getting started, how it works (catalog tail, scope, scheduling), tuning (presets), example READMEs (no manual scheduling)

## [0.6.0] — 2026-11-12

Simpler still: the model serves its feed, the jobs schedule themselves, and the published config is short.

### Added
- `Model::recommendedFor($user, ?$feed)` on the `Recommendable` trait — `Route::get('/posts/for-you', fn (Request $r) => Post::recommendedFor($r->user()))`; `Recommender::feedFor($model)` finds the model's feed
- **Self-scheduling**: `update-scores` (every 10 min), `build-neighbours` (nightly) and `prune` (daily) are registered with Laravel's scheduler (`withoutOverlapping()->onOneServer()`); `schedule.*` config, `RECOMMENDER_SCHEDULE=false` to opt out
- An empty first page logs one notice per feed per hour pointing at `recommender:doctor`

### Changed
- `vendor:publish --tag=recommender-config`, `make-feed` and `bootstrap` publish a **short** config (`enabled`, `cache_store`, `feeds`); defaults stay in the package. `--tag=recommender-config-full` publishes everything
- README first feed is three steps (bootstrap, model, route); maintenance commands live in operations

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

[Unreleased]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.8...HEAD
[0.10.8]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.7...v0.10.8
[0.10.7]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.6...v0.10.7
[0.10.6]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.5...v0.10.6
[0.10.5]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.4...v0.10.5
[0.10.4]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.3...v0.10.4
[0.10.3]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.2...v0.10.3
[0.10.2]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.1...v0.10.2
[0.10.1]: https://github.com/solutionforest/laravel-recommender/compare/v0.10.0...v0.10.1
[0.10.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.8.5...v0.9.0
[0.8.5]: https://github.com/solutionforest/laravel-recommender/compare/v0.8.4...v0.8.5
[0.8.4]: https://github.com/solutionforest/laravel-recommender/compare/v0.8.3...v0.8.4
[0.8.3]: https://github.com/solutionforest/laravel-recommender/compare/v0.8.2...v0.8.3
[0.8.2]: https://github.com/solutionforest/laravel-recommender/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/solutionforest/laravel-recommender/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/solutionforest/laravel-recommender/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.6.3...v0.7.0
[0.6.3]: https://github.com/solutionforest/laravel-recommender/compare/v0.6.2...v0.6.3
[0.6.2]: https://github.com/solutionforest/laravel-recommender/compare/v0.6.1...v0.6.2
[0.6.1]: https://github.com/solutionforest/laravel-recommender/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/solutionforest/laravel-recommender/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/solutionforest/laravel-recommender/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/solutionforest/laravel-recommender/compare/v0.0.15...v0.1.0
[0.0.15]: https://github.com/solutionforest/laravel-recommender/compare/v0.0.14...v0.0.15
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
