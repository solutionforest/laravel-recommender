<?php

namespace SolutionForest\Recommender\Eloquent;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;

/**
 * Default filter for feeds with a `model`: one primary-key query that keeps
 * ids that still exist and pass the model's optional `recommendable` scope.
 *
 *     public function scopeRecommendable(Builder $query, Context $context): void
 *     {
 *         $query->where('published', true)
 *             ->when($context->userId, fn ($q, $id) => $q->where('author_id', '<>', $id));
 *     }
 */
final class ModelFilter implements ItemFilter
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(private readonly string $model)
    {
    }

    public function allowed(Context $context, array $ids): array
    {
        $instance = new $this->model;

        return Ids::normalizeAll(
            RecommendableQuery::for($instance, $context)
                ->whereKey($ids)
                ->pluck($instance->getQualifiedKeyName())
                ->all(),
        );
    }
}
