<?php

namespace SolutionForest\Recommender\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SolutionForest\Recommender\Support\FeedPage page(string $feed, int|string|null $userId, int $page = 1, ?int $perPage = null, array<string, mixed> $attributes = [])
 * @method static list<int|string> build(string $feed, int|string $userId, ?list<int|string> $seen = null, array<string, mixed> $attributes = [])
 * @method static list<int|string> trending(string $feed, array<string, mixed> $attributes = [])
 * @method static void warm(string $feed, int|string $userId)
 * @method static void forgetCard(string $feed, int|string $id)
 * @method static void forgetUser(string $feed, int|string $userId)
 * @method static void forgetTrending(string $feed)
 * @method static list<array{id: int|string, score: float, sources: array<string, float>}> explain(string $feed, int|string|null $userId, int $limit = 20)
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
