<?php

namespace SolutionForest\Recommender\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Support\Context;

/**
 * @internal
 */
final class RecommendableQuery
{
    /**
     * A query for $model with its `recommendable` scope applied, if it has one.
     *
     * @return Builder<Model>
     */
    public static function for(Model $model, Context $context): Builder
    {
        $query = $model->newQuery();

        return $model->hasNamedScope('recommendable')
            ? $query->scopes(['recommendable' => [$context]])
            : $query;
    }
}
