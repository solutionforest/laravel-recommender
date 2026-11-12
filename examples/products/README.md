# Example: product catalog (three event types)

Shows that interactions are **not only likes/views**. Here we stream:

| Table | Event | Default weight |
|---|---|---|
| `product_views` | `view` | 1.0 |
| `product_likes` | `like` | 4.0 |
| `product_purchases` | `purchase` | 10.0 |

Covered by [`ProductsExampleTest`](../../tests/Feature/ProductsExampleTest.php).

```mermaid
flowchart LR
  V[(views)] & L[(likes)] & P[(purchases)] --> I[ProductInteractionSource]
  I --> J["update-scores / build-neighbours"]
  J --> R[Recommender]
  PR[(products)] --> F[VisibleProductFilter] --> R
  R --> C[ProductCardTransformer] --> API["GET /products/recommended"]
```

## Try it

```bash
vendor/bin/pest tests/Feature/ProductsExampleTest.php
```

## Use in your app

1. Copy `src/*` → `app/Recommendation/Products` (fix namespaces).
2. Merge `config/recommender.php` feed `products`.
3. Add the route.
4. Yield any extra event names you need — register them under `events` / `positive_events`.
