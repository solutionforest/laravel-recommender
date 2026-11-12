# Examples

These integrations are exercised by Pest. Every example is a **config-only feed** — `model` + `interactions` in the config, a `recommendable` scope and `toRecommendationCard()` on the model — plus custom sources and boosters where the product needs its own rules. Adapt schemas, namespaces and weights before using them in your application.

| Example | Shows | Test |
|---|---|---|
| [`simple/`](simple/) | **Start here** — config only: `model` + interaction tables, no classes | `SimpleExampleTest` |
| [`blog/`](blog/) | Likes + views → posts; sponsored source + booster; one post per author per page | `BlogExampleTest` |
| [`products/`](products/) | **Three events** (`view` / `like` / `purchase`) → catalog | `ProductsExampleTest` |
| [`events/`](events/) | **Meetup listing** (`view` / `like` / `join`) → recommended events | `EventsExampleTest` |
| [`events-advanced/`](events-advanced/) | `events/` + district, host party, type and comments as custom sources / boosters; a custom `CardTransformer` for comment counts | `EventsAdvancedExampleTest` |
| [`reviews/`](reviews/) | Shop UGC + custom `PetTypeMatchSource` | `ReviewsExampleTest` |

## `Context` in examples

Sources, boosters and the model's `recommendable` scope receive `Context $context`. That is the package’s ranking bag:

- `$context->userId` — viewer from `recommendations($feed, $user)` / `Recommender::page(…)` (`null` = guest)
- `$context->now()` — clock for date filters
- `$context->feed` — feed config

Not the HTTP request. Full guide: [docs/reference.md → What is Context?](../docs/reference.md#what-is-context).

## Folder layout (every example)

```
example/
  config/recommender.php   # model + interactions + weights — the whole feed
  database/migrations/
  README.md
  src/
    Models/          # scopeRecommendable() + toRecommendationCard() (+ Recommendable trait)
    Http/            # one-line controller: return recommendations('feed', $request->user())
    Sources/         # optional CandidateSource (your own rules: fresh, same district, …)
    Boosters/        # optional Booster (re-score items already in the pool)
    Cards/           # optional CardTransformer, only when a card needs more than one row
    Support/         # optional helpers
```

Diversity keys are model attributes: `'diversity' => ['author_id' => 1]` keeps one post per author per page.

```bash
vendor/bin/pest tests/Feature/SimpleExampleTest.php
vendor/bin/pest tests/Feature/BlogExampleTest.php
vendor/bin/pest tests/Feature/ProductsExampleTest.php
vendor/bin/pest tests/Feature/EventsExampleTest.php
vendor/bin/pest tests/Feature/EventsAdvancedExampleTest.php
vendor/bin/pest tests/Feature/ReviewsExampleTest.php
```

Start with [docs/tutorial.md](../docs/tutorial.md), then copy the example closest to your product.
