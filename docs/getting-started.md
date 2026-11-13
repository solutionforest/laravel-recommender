# Getting started

Laravel Recommender helps you suggest items from your own application. You do not need to choose a formula to get started. First make sure your app records interactions, then follow the [first-feed tutorial](tutorial.md).

## Five terms

| Term | Meaning | Example |
|---|---|---|
| Item | Something to recommend | A post |
| Interaction | An action by a user on an item | User 7 likes post 12 |
| Feed | A named set of recommendation settings | `posts` |
| Source | A way to find possible items | Trending posts or items liked together |
| Card | The fields returned for an item | An ID, title and image URL |

A **neighbour** is an item frequently interacted with by the same users as another item. A **weight** controls how much a source or event contributes. You can leave the generated weights alone while setting up.

## What you need to supply

The package does not collect views or likes. Your app needs item records and interaction records containing a user ID, an item ID and a timestamp — usually one table per interaction type (`post_likes`, `post_views`). IDs can be integers, UUIDs or ULIDs. For most apps that is all the configuration needed:

```php
'posts' => [
    'preset' => 'social',   // catalog | social | events: sensible weights in one line
    'model' => App\Models\Post::class,
    'interactions' => ['post_likes' => 'like', 'post_views' => 'view'],
],
```

Your app also decides which items may be shown (a `recommendable` scope on the model) and which fields a card contains (`toRecommendationCard()`). `php artisan recommender:bootstrap posts` configures the feed; add the optional model methods to apply your own rules and card fields.

Background jobs — scheduled by the package, you only run Laravel's scheduler — read the interactions and store derived scores and neighbours. The first request for a user may build a ranked list, which is then cached. A guest uses a shared trending list. A new user may have little personal signal, and a new catalog may have no scored items yet.

## Continue

1. [Create and verify a feed](tutorial.md).
2. [Understand the request and ranking pipeline](how-it-works.md).
3. [Adjust the ranking](tuning.md) only after you can see results.
4. [Advanced usage](advanced.md): your own sources and boosters, custom classes, several feeds, A/B tests, testing.

For configuration and method signatures use the [reference](reference.md); to monitor feeds use the [dashboard](dashboard.md); for scheduling, caching and recovery use [operations](operations.md). The [maths](maths.md) is optional.
