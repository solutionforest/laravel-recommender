# Simple example — config only

A feed with **no custom classes**: the package reads interactions straight from your tables and uses your Eloquent model for visibility and cards.

```php
// config/recommender.php
'feeds' => [
    'articles' => [
        'model' => Article::class,
        'interactions' => [
            'article_likes' => 'like',
            'article_views' => 'view',
        ],
    ],
],
```

Each interaction table needs `user_id`, `article_id` (`{model}_id`), `created_at` and an auto-increment `id`. Different columns? Set `item_column` / `user_column` / `timestamp_column` on the feed, or per table: `'bookmarks' => ['event' => 'like', 'item' => 'bookmarkable_id']`. Ids can be integers, UUIDs or ULIDs.

On the model (both optional):

- `scopeRecommendable(Builder $query, Context $context)` — what may be shown (`$context->userId` is the viewer, `null` for guests).
- `toRecommendationCard(): array` — the JSON for each item (defaults to `toArray()`).
- `use Recommendable;` — forget cached cards on save / delete.

Serve it:

```php
Route::get('/articles/for-you', fn (Request $request) => Article::recommendedFor($request->user()));
```

Returning the page gives Laravel's paginator JSON (`?page=` is read automatically). Schedule `recommender:update-scores` and `recommender:build-neighbours` as usual.

| File | Role |
|---|---|
| [config/recommender.php](config/recommender.php) | The whole feed |
| [src/Models/Article.php](src/Models/Article.php) | Visibility scope + card |
| [database/migrations](database/migrations) | Example tables |

Need more control? Every piece can still be replaced with a class — see the other examples.
