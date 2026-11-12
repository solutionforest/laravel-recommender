<?php

namespace SolutionForest\Recommender\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SolutionForest\Recommender\Support\FeedPage page(string $feed, \Illuminate\Contracts\Auth\Authenticatable|int|string|null $user = null, ?int $page = null, ?int $perPage = null, array<string, mixed> $attributes = [])
 * @method static list<int|string> build(string $feed, \Illuminate\Contracts\Auth\Authenticatable|int|string $user, ?list<int|string> $seen = null, array<string, mixed> $attributes = [])
 * @method static list<int|string> trending(string $feed, array<string, mixed> $attributes = [])
 * @method static void warm(string $feed, \Illuminate\Contracts\Auth\Authenticatable|int|string $user)
 * @method static void forgetCard(string $feed, int|string $id)
 * @method static void forgetModel(\Illuminate\Database\Eloquent\Model $model)
 * @method static void forgetUser(string $feed, \Illuminate\Contracts\Auth\Authenticatable|int|string $user)
 * @method static void forgetTrending(string $feed)
 * @method static list<array{id: int|string, score: float, sources: array<string, float>, contributions: array<string, float>, final: ?int, cached: ?int, exploration: bool}> explain(string $feed, \Illuminate\Contracts\Auth\Authenticatable|int|string|null $user, int $limit = 20)
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
