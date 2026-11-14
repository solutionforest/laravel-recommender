<?php

namespace SolutionForest\Recommender\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Contracts\DiversityGroups;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Eloquent\ModelCards;
use SolutionForest\Recommender\Eloquent\ModelFilter;
use SolutionForest\Recommender\Eloquent\ModelGroups;
use SolutionForest\Recommender\Interactions\DatabaseInteractions;

/**
 * Resolves a feed's app bindings (interactions / filter / cards / groups).
 *
 * Every binding can be a class name (resolved from the container). When it
 * is omitted, the feed's `model` and `interactions` table map are used
 * instead, so a simple feed needs no classes at all:
 *
 *     'posts' => [
 *         'model' => Post::class,
 *         'interactions' => ['post_likes' => 'like', 'post_views' => 'view'],
 *     ],
 */
final class Bindings
{
    public function __construct(private readonly Container $container)
    {
    }

    public function interactions(FeedDefinition $feed): ?InteractionSource
    {
        $binding = $feed->get('interactions');

        return match (true) {
            is_array($binding) && $binding !== [] => new DatabaseInteractions($feed),
            is_string($binding) && $binding !== '' => $this->make($binding, InteractionSource::class, $feed, 'interactions'),
            default => null,
        };
    }

    public function filter(FeedDefinition $feed): ?ItemFilter
    {
        if ($class = $feed->get('filter')) {
            return $this->make($class, ItemFilter::class, $feed, 'filter');
        }

        return ($model = $this->model($feed)) ? new ModelFilter($model) : null;
    }

    public function cards(FeedDefinition $feed): CardTransformer
    {
        if ($class = $feed->get('cards')) {
            return $this->make($class, CardTransformer::class, $feed, 'cards');
        }
        if ($model = $this->model($feed)) {
            return new ModelCards($model, $feed);
        }

        throw new InvalidArgumentException("Feed [{$feed->name}] needs a 'model' or a 'cards' transformer.");
    }

    public function groups(FeedDefinition $feed): ?DiversityGroups
    {
        if ($class = $feed->get('groups')) {
            return $this->make($class, DiversityGroups::class, $feed, 'groups');
        }
        $model = $this->model($feed);

        return $model ? new ModelGroups($model, array_keys((array) $feed->get('diversity', []))) : null;
    }

    /**
     * @return class-string<\Illuminate\Database\Eloquent\Model>|null
     */
    private function model(FeedDefinition $feed): ?string
    {
        $model = $feed->get('model');
        if ($model === null || $model === '') {
            return null;
        }
        if (! is_string($model) || ! is_a($model, \Illuminate\Database\Eloquent\Model::class, true)) {
            throw new InvalidArgumentException("Feed [{$feed->name}] 'model' must be an Eloquent model class.");
        }

        return $model;
    }

    /**
     * Resolve a configured class and check it implements the contract.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function make(mixed $class, string $contract, FeedDefinition $feed, string $key): object
    {
        $instance = is_string($class) && $class !== '' ? $this->container->make($class) : null;
        if (! $instance instanceof $contract) {
            $given = is_string($class) ? $class : get_debug_type($class);
            throw new InvalidArgumentException("Feed [{$feed->name}] '{$key}' must be a class implementing {$contract}, {$given} given.");
        }

        return $instance;
    }
}
