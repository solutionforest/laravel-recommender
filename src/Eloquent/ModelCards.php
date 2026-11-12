<?php

namespace SolutionForest\Recommender\Eloquent;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\FeedDefinition;

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
     */
    public function __construct(
        private readonly string $model,
        private readonly FeedDefinition $feed,
    ) {
    }

    public function cards(array $ids): array
    {
        $instance = new $this->model;
        $out = [];

        foreach (RecommendableQuery::for($instance, new Context($this->feed, null))->whereKey($ids)->get() as $item) {
            /** @var Model $item */
            $out[$item->getKey()] = method_exists($item, 'toRecommendationCard')
                ? $item->toRecommendationCard()
                : $item->toArray();
        }

        return $out;
    }
}
