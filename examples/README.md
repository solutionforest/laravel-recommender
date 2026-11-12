# Examples

These integrations are exercised by Pest. Adapt their schemas, namespaces, visibility rules and configuration before using them in your application.

| Example | Shows | Test |
|---|---|---|
| [`simple/`](simple/) | **Start here** — config only: `model` + interaction tables, no classes | `SimpleExampleTest` |
| [`blog/`](blog/) | Likes + views → posts; sponsors; diversity | `BlogExampleTest` |
| [`products/`](products/) | **Three events** (`view` / `like` / `purchase`) → catalog | `ProductsExampleTest` |
| [`events/`](events/) | **Meetup listing** (`view` / `like` / `join`) → recommended events | `EventsExampleTest` |
| [`events-advanced/`](events-advanced/) | Full copy of `events/` + district, host party, type, comments; for simple view/like/join see `events/` | `EventsAdvancedExampleTest` |
| [`reviews/`](reviews/) | Shop UGC + custom `PetTypeMatchSource` | `ReviewsExampleTest` |

## `Context` in examples

Sources / boosters / filters take `Context $context` as the first argument. That is the package’s ranking bag:

- `$context->userId` — viewer from `Recommender::page(…, $userId)` (`null` = guest)
- `$context->now()` — clock for date filters
- `$context->feed` — feed config

Not the HTTP request. Full guide: [docs/reference.md → What is Context?](../docs/reference.md#what-is-context).

## Folder layout (every example)

```
example/
  config/recommender.php
  database/migrations/
  README.md
  src/
    Models/          # Eloquent items
    Interactions/    # InteractionSource (views / likes / …)
    Sources/         # CandidateSource (what to recommend)
    Boosters/        # Booster (re-score items already in the pool)
    Filters/         # ItemFilter (visibility)
    Cards/           # CardTransformer (API payload)
    Groups/          # DiversityGroups
    Http/            # Controllers
    Support/         # Helpers (optional)
```

```bash
vendor/bin/pest tests/Feature/BlogExampleTest.php
vendor/bin/pest tests/Feature/ProductsExampleTest.php
vendor/bin/pest tests/Feature/EventsExampleTest.php
vendor/bin/pest tests/Feature/EventsAdvancedExampleTest.php
vendor/bin/pest tests/Feature/ReviewsExampleTest.php
```

Start with [docs/tutorial.md](../docs/tutorial.md), then copy the example closest to your product.
