---
name: laravel-recommender
description: >-
  Wire solutionforest/laravel-recommender into a Laravel app: scaffold feeds,
  InteractionSource / ItemFilter / CardTransformer, config weights, scores /
  neighbours jobs, FeedPage::toPaginator, cache locks. Use when adding a
  recommendation engine, for-you feed, personalised ranking, co-interaction /
  trending, inspiration or blog recommendations, or replacing RAND()/Personalize.
---

# Laravel Recommender — simple recommendation engine

Package: `solutionforest/laravel-recommender` (PHP **8.3+**, Laravel **11–13**).

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
| `…/examples/` | `blog` · `products` (view/like/purchase) · `reviews` (+ PetTypeMatchSource) |

---

## Do this (in order)

1. **Install**

```bash
composer require solutionforest/laravel-recommender:^0.0.10
php artisan migrate
```

Creates `rec_item_scores`, `rec_item_neighbours`, `rec_state`.

2. **Scaffold a feed** (do not hand-roll the three classes from scratch)

```bash
php artisan recommender:make-feed posts
# or reviews-style:
php artisan recommender:make-feed reviews --model=App\\Models\\Comment --likes=user_fav_comments --views=user_clicks --item-column=comment_id
```

Produces under `app/Recommendation/{Feed}/`:

- `*InteractionSource` — streams interactions from **app** tables (any event names: like / view / share / …)  

- `*ItemFilter` / `Visible*` — who may see an item  
- `*CardTransformer` — JSON/card shape (no viewer-specific fields)

Registers `config/recommender.php` → `feeds.{name}`.

3. **Edit the three classes** to match real table/column names (`TABLES`, `ITEM_COLUMN`, published rules, owner column).

4. **Cook once**

```bash
php artisan recommender:update-scores {feed}
php artisan recommender:build-neighbours {feed}
php artisan recommender:explain {feed} {userId}
php artisan recommender:status
```

Historical dumps: widen `scores_backfill_days` **or** clear `rec_item_scores` + `rec_state` watermark for that feed, then rebuild.

5. **Serve**

```php
use SolutionForest\Recommender\Facades\Recommender;

$page = Recommender::page('posts', $user?->id, (int) $request->query('page', 1));

return response()->json($page->toPaginatorArray(['path' => $request->url()]));
// Livewire: $page->toPaginator()
```

6. **Schedule** (prod): scores ~every 10 min · neighbours nightly · prune daily. Queue `recommender`. Cache store with locks: **redis** (preferred) or **database** + `cache_locks` (L11–13). Override: `RECOMMENDER_CACHE_STORE`.

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

Implement `CandidateSource` / `Booster` / `DiversityGroups` when the product needs rules the package does not know (same pet type, related shops, sponsored ids). Keep queries indexed + `LIMIT`. Guests: `$context->userId` may be `null`.

Sponsored pattern: **Source** (inject) + **Booster** (push) + diversity cap. See `examples/blog`.

---

## Agent rules

- Prefer `recommender:make-feed` over inventing classes.
- Never invent a Python/Personalize service for this package’s job.
- Do not put viewer-specific fields (e.g. `fav_by_user`) in `CardTransformer` — hydrate in the app layer if needed.
- Bulk writes only inside package code; in apps, use package commands/jobs.
- Empty feed / only trending → jobs not run or backfill too short.
- No CoInteraction in `explain` → neighbours empty → run `build-neighbours`.
- Keep README thin; depth stays in `docs/`.

## Install this skill in an app (Cursor)

Copy or symlink into the consuming project:

```bash
mkdir -p .cursor/skills
cp -R vendor/solutionforest/laravel-recommender/skills/laravel-recommender .cursor/skills/
# or: ln -s ../../vendor/solutionforest/laravel-recommender/skills/laravel-recommender .cursor/skills/laravel-recommender
```
