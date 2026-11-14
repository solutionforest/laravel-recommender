<?php

namespace SolutionForest\Recommender\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Support\Value;

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
        if (! $model->hasNamedScope('recommendable')) {
            return $query;
        }

        // The scope edits $query in place; scopes() hands back what it returns, or $query.
        $scoped = $query->scopes(['recommendable' => [$context]]);

        return $scoped instanceof Builder ? $scoped : $query;
    }

    /**
     * Key values from pluck() as ids (int or string, as stored).
     *
     * @param  array<mixed>  $values
     * @return list<int|string>
     */
    public static function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = Value::id($value);
            if ($id !== null) {
                $ids[] = Ids::normalize($id);
            }
        }

        return $ids;
    }
}
