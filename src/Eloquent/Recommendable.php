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

    /**
     * How this item is doing in a feed: positive / exposure counts, rate,
     * quality (0..1, confidence-adjusted) and popularity (0..1).
     *
     * @return array{positive: int, exposure: int, impressions: int, rate: ?float, quality: float, popularity: float, last_event_at: ?string}
     */
    public function recommendationStats(string $feed, ?int $days = null): array
    {
        $stats = app(Recommender::class)->stats($feed, $this, $days);

        return reset($stats) ?: ['positive' => 0, 'exposure' => 0, 'impressions' => 0, 'rate' => null, 'quality' => 0.0, 'popularity' => 0.0, 'last_event_at' => null];
    }
}
