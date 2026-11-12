<?php

namespace SolutionForest\Recommender\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SolutionForest\Recommender\Support\FeedPage page(string $feed, int|string|null $userId, int $page = 1, ?int $perPage = null, array $attributes = [])
 * @method static list<int|string> build(string $feed, int|string $userId, ?array $seen = null, array $attributes = [])
 * @method static list<int|string> trending(string $feed, array $attributes = [])
 * @method static void warm(string $feed, int|string $userId)
 * @method static void forgetCard(string $feed, int|string $id)
 * @method static void forgetUser(string $feed, int|string $userId)
 * @method static void forgetTrending(string $feed)
 * @method static array explain(string $feed, int|string|null $userId, int $limit = 20)
 * @method static \SolutionForest\Recommender\Support\FeedDefinition feed(string $name)
 * @method static bool enabled()
 *
 * @see \SolutionForest\Recommender\Recommender
 */
class Recommender extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SolutionForest\Recommender\Recommender::class;
    }
}
