---
name: laravel-recommender
description: >-
  Wire solutionforest/laravel-recommender into a Laravel app: config-only feeds
  (model + interaction tables), recommendations() helper, config weights, scores /
  neighbours jobs, similar items ($post->similar()), recommender:doctor, the /recommender dashboard, item stats,
  cache locks. Use when adding a recommendation engine, for-you feed, personalised ranking, related posts /
  "you may also like", co-interaction / trending, inspiration or blog recommendations, or replacing RAND()/Personalize.
---

# Laravel Recommender — simple recommendation engine

Package: `solutionforest/laravel-recommender` (PHP **8.3+**, Laravel **10–13**).

Self-hosted “for you” feeds: item–item collaborative filtering + time-decayed trending + optional custom sources. **Not** a neural net / AWS Personalize.

Human docs (prefer linking over pasting):

| Doc | When |
|---|---|
| `vendor/solutionforest/laravel-recommender/docs/tutorial.md` | First scaffold checklist |
| `…/docs/getting-started.md` | Slower first-time wiring |
| `…/docs/reference.md` | API / config / cache locks |
| `…/docs/tuning.md` | Weight recipes |
| `…/docs/operations.md` | Go-live / FAQ |
| `…/AGENTS.md` | Short agent rules |
| `…/examples/` | `simple` · `blog` · `products` · `events` · `events-advanced` · `reviews` — all config-only (`model` + `interactions`); `Models/` hold `scopeRecommendable()` / `toRecommendationCard()`, plus optional `Sources/` · `Boosters/` · `Cards/` · `Http/` |

---

## Do this (in order)

1. **Install**

```bash
composer require solutionforest/laravel-recommender
php artisan migrate
```

Creates `rec_item_scores`, `rec_item_daily`, `rec_item_neighbours`, `rec_state`.

2. **Register a feed** — config only; prefer this. Fastest: `php artisan recommender:bootstrap posts` (migrate + wizard + first build + doctor).

```bash
php artisan recommender:make-feed                       # interactive wizard (presets + optional 3rd event)
php artisan recommender:make-feed posts -n
php artisan recommender:make-feed products --preset=products -n
php artisan recommender:make-feed reviews --model=App\\Models\\Comment --likes=user_fav_comments --views=user_clicks --item-column=comment_id -n
```

It writes `feeds.{name}` with `preset` (catalog / social / events — feed keys override it), `model` + `interactions` (`[table => event]`; `item_column` only if not `{model}_id`) and prints two optional model methods:
`scopeRecommendable(Builder $q, Context $context)` (who may see an item) and `toRecommendationCard()` (JSON, no viewer-specific fields), plus `use Recommendable;` for card invalidation. Ids may be int / UUID / ULID. See `examples/simple`.

   Custom queries (single events table, joins): `make-feed posts --classes` scaffolds `InteractionSource` / `ItemFilter` / `CardTransformer` under `app/Recommendation/{Feed}/`.

3. **Check it**: `php artisan recommender:doctor {feed}` — tables, columns, event weights, classes; fix what it reports.

4. **Cook once**

```bash
php artisan recommender:update-scores {feed}
php artisan recommender:build-neighbours {feed}
php artisan recommender:explain {feed} {userId}
php artisan recommender:status
```

Historical imports: set `scores_backfill_days` before the first run. For a later rebuild run `php artisan recommender:rebuild-scores {feed} --days=… --dry-run`, then without `--dry-run`; never replay over existing scores by hand.

5. **Serve**

```php
use SolutionForest\Recommender\Facades\Recommender;

// Returns paginator JSON; reads ?page= automatically. Accepts the user model, an id, or null.
Route::get('/posts/for-you', fn (Request $request) => Post::recommendedFor($request->user())); // model uses Recommendable
// or by feed name: recommendations('posts', $request->user())

// Same thing: Recommender::page('posts', $request->user())
// Livewire: Recommender::page('posts', $user)->toPaginator()

// Related posts on a post page (same for every viewer, cached per item):
$related = $post->similar(4);   // cards, most similar first; [] until the post has neighbours
```

6. **Schedule**: automatic (scores ~every 10 min · neighbours nightly · prune daily) — just run Laravel's scheduler; `RECOMMENDER_SCHEDULE=false` to do it yourself. Queue `recommender`. Cache store with locks: **redis** (preferred) or **database** + `cache_locks` (L10–13). Override: `RECOMMENDER_CACHE_STORE`.

---

## Config mental model (`config/recommender.php`)

Bigger weight = that signal counts more (relative).

| Knob | Effect |
|---|---|
| `events.{name}` | Trending “hot” — **any** event string (`view`/`like`/`share`/…); default like ≈ 4× view |
| `positive_events` / `exposure_events` | Quality (Wilson) — list which of your events are “good” vs “seen” |
| `sources` | Blend pool (CoInteraction, Trending, custom…) |
| `boosters` | Re-score items already in pool (Quality, Sponsored…) |
| `exploration_ratio` | Reserved slots for explore sources (not a source weight) |
| `diversity` | Caps per page (`author` / `shop` / …) |

After weight changes:

```php
Recommender::forgetUser('posts', $userId);
Recommender::forgetTrending('posts');
```

---

## Custom sources (product rules)

Implement `CandidateSource` / `Booster` / `DiversityGroups` (worked examples: `docs/advanced.md`) when the product needs rules the package does not know (same pet type, related shops, sponsored ids). Keep queries indexed + `LIMIT`.

**`Context`** (`Support\Context`) is the first argument to those methods — not the HTTP request. `$context->userId` comes from `Recommender::page($feed, $userId, …)` (`null` = guest). Optional model: `page(…, attributes: ['user' => $user])` → `$context->attribute('user')`. Docs: `docs/reference.md` → What is Context? If an attribute changes which items appear (tenant, region), add it to the feed's `list_vary_by`, otherwise one user's cached list leaks across values; a signed-in feed is sequential (follow `next_cursor`, no `total`).

Scoring helpers (`SolutionForest\Recommender\Scoring\Math`) — prefer these over hand-rolled `1/(1+$i)`:

| Helper | When |
|---|---|
| `scoreInOrder($rows)` | Ordered query → scores |
| `scoreInOrder($rows, fn)` | Affinity × soft order |
| `preferRanked($ids, $fav, $attr)` | Booster for favorite keys |
| `fromPosition` / `fromAffinity` / `fromAge` | Single-value building blocks |
| `normalizeMax($scores)` | Booster max=1 |

Docs: `docs/maths.md` §5. Example: `examples/events-advanced` (`JoinPreferences` + Event scopes).

Sponsored pattern: **Source** (inject) + **Booster** (push) + diversity cap. See `examples/blog`.

---

## Agent rules

- Prefer `recommender:make-feed` over inventing classes.
- Never invent a Python/Personalize service for this package’s job.
- Do not put viewer-specific fields (e.g. `fav_by_user`) in `toRecommendationCard()` / `CardTransformer` — cards are shared; hydrate in the app layer if needed.
- Bulk writes only inside package code; in apps, use package commands/jobs.
- Empty feed / only trending → jobs not run or backfill too short.
- No CoInteraction in `explain` → neighbours empty → run `build-neighbours`.
- "Related / you may also like" → `$model->similar($n)` (or `Recommender::similar($feed, $model, $n)`), not a hand-written co-occurrence query or a user × item table. It is not personal (no seen-set, viewer rules not applied); use `page()` for a for-you feed. Pass a loaded model or a validated id, never a raw request value (each unknown id leaves a short-lived cache entry). Empty for new items → hide the block or fall back to your own query.
- Keep README thin; depth stays in `docs/`.

## Install this skill in an app (Cursor)

Copy or symlink into the consuming project:

```bash
mkdir -p .cursor/skills
cp -R vendor/solutionforest/laravel-recommender/skills/laravel-recommender .cursor/skills/
# or: ln -s ../../vendor/solutionforest/laravel-recommender/skills/laravel-recommender .cursor/skills/laravel-recommender
```
