<?php

namespace SolutionForest\Recommender\Eloquent;

use SolutionForest\Recommender\Recommender;

/**
 * Optional model trait: drops the cached card of every feed that uses this
 * model whenever an item is saved or deleted, so edits show up immediately.
 *
 *     class Post extends Model
 *     {
 *         use Recommendable;
 *     }
 */
trait Recommendable
{
    public static function bootRecommendable(): void
    {
        $forget = static function ($model): void {
            app(Recommender::class)->forgetModel($model);
        };

        static::saved($forget);
        static::deleted($forget);
    }
}
