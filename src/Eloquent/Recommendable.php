<?php

namespace SolutionForest\Recommender\Eloquent;

use SolutionForest\Recommender\Recommender;

/**
 * Model trait: the model serves its own feed, and edits show up at once
 * (cached cards are dropped on save / delete).
 *
 *     class Post extends Model
 *     {
 *         use Recommendable;
 *     }
 *
 *     Route::get('/posts/for-you', fn (Request $r) => Post::recommendedFor($r->user()));
 *     $post->similar(6); // related posts
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
     * One page of this model's feed for $user (a model, an id, or null for
     * guests). The feed is the one whose `model` is this class; pass $feed
     * when several feeds use it. Reads `?page=` / `?cursor=` like paginate().
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function recommendedFor(
        \Illuminate\Contracts\Auth\Authenticatable|int|string|null $user = null,
        ?string $feed = null,
        ?int $perPage = null,
        array $attributes = [],
    ): \SolutionForest\Recommender\Support\FeedPage {
        $recommender = app(Recommender::class);

        return $recommender->page($feed ?? $recommender->feedFor(static::class), $user, null, $perPage, $attributes);
    }

    /**
     * Items similar to this one, as cards (most similar first), e.g.
     * `$post->similar(6)` for a related-posts block. See
     * {@see Recommender::similar()}.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function similar(int $limit = 6, ?string $feed = null, array $attributes = []): array
    {
        $recommender = app(Recommender::class);

        return $recommender->similar($feed ?? $recommender->feedFor($this), $this, $limit, $attributes);
    }

    /**
     * Record that $user did $event with this item, in the feed's own table:
     * `$post->recordInteraction($user, 'like', once: true)`. See
     * {@see Recommender::recordInteraction()}.
     *
     * @return bool true when a row was added
     */
    public function recordInteraction(
        \Illuminate\Contracts\Auth\Authenticatable|int|string $user,
        string $event,
        bool $once = false,
        ?string $feed = null,
        ?string $table = null,
    ): bool {
        $recommender = app(Recommender::class);

        return $recommender->recordInteraction($feed ?? $recommender->feedFor($this), $this, $user, $event, $once, $table);
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
