<?php

namespace SolutionForest\Recommender\Eloquent;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;

/**
 * Newest items the model's `recommendable` scope allows, for when a feed's
 * ranked and trending items run out (so scrolling never hits a wall).
 *
 * Ordered by `tail_order` (default: the model's created_at, or its key when
 * it has no timestamps), newest first, with the key as tie-breaker.
 */
final class CatalogTail
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private readonly string $model,
        private readonly ?string $orderColumn = null,
    ) {
    }

    /**
     * @param  list<int|string>  $exclude  ids already served or queued
     * @return list<int|string>
     */
    public function ids(Context $context, array $exclude, int $limit, int $offset = 0): array
    {
        if ($limit <= 0) {
            return [];
        }

        $instance = new $this->model;
        $key = $instance->getQualifiedKeyName();
        $order = $this->orderColumn
            ?? ($instance->usesTimestamps() && $instance->getCreatedAtColumn() ? $instance->qualifyColumn($instance->getCreatedAtColumn()) : $key);

        return Ids::normalizeAll(
            RecommendableQuery::for($instance, $context)
                ->when($exclude !== [], fn ($q) => $q->whereNotIn($key, $exclude))
                ->orderByDesc($order)
                ->orderByDesc($key)
                ->offset($offset)
                ->limit($limit)
                ->pluck($key)
                ->all(),
        );
    }
}
