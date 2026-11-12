# Examples

Copy-paste integrations covered by Pest so the docs cannot rot.

| Example | Shows | Test |
|---|---|---|
| [`blog/`](blog/) | Likes + views → posts; sponsors; diversity | `BlogExampleTest` |
| [`products/`](products/) | **Three events** (`view` / `like` / `purchase`) → catalog | `ProductsExampleTest` |
| [`events/`](events/) | **Meetup listing** (`view` / `like` / `join`) → recommended events | `EventsExampleTest` |
| [`reviews/`](reviews/) | Shop UGC + custom `PetTypeMatchSource` | `ReviewsExampleTest` |

```bash
vendor/bin/pest tests/Feature/BlogExampleTest.php
vendor/bin/pest tests/Feature/ProductsExampleTest.php
vendor/bin/pest tests/Feature/EventsExampleTest.php
vendor/bin/pest tests/Feature/ReviewsExampleTest.php
```

Start with [docs/tutorial.md](../docs/tutorial.md), then copy the example closest to your product.
