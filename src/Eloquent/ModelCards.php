<?php

namespace SolutionForest\Recommender\Eloquent;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Value;

/**
 * Default cards for feeds with a `model`: loads the ids in one query and
 * returns `$model->toRecommendationCard()` when the model defines it,
 * otherwise `$model->toArray()`.
 *
 * Cards are cached and shared by every viewer, so the `recommendable` scope
 * runs as a guest here (viewer-specific rules belong in the filter).
 */
final class ModelCards implements CardTransformer
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly string $model,
        private readonly FeedDefinition $feed,
        private readonly array $attributes = [],
    ) {
    }

    /**
     * Same cards, rendered with request attributes (used with `cards_vary_by`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self($this->model, $this->feed, $attributes);
    }

    public function cards(array $ids): array
    {
        $instance = new $this->model;
        $out = [];

        $context = new Context($this->feed, null, [], $this->attributes);
        foreach (RecommendableQuery::for($instance, $context)->whereKey($ids)->get() as $item) {
            /** @var Model $item */
            $key = Value::id($item->getKey());
            if ($key === null) {
                continue;
            }
            $out[$key] = Value::map(method_exists($item, 'toRecommendationCard')
                ? $item->toRecommendationCard($context) // the context may be ignored
                : $item->toArray());
        }

        return $out;
    }
}
