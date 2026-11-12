<?php

namespace SolutionForest\Recommender\Examples\Reviews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\Recommendable;
use SolutionForest\Recommender\Support\Context;

/**
 * @property int $id
 * @property int $user_id
 * @property int $shop_id
 * @property string $title
 * @property string|null $cover_url
 * @property bool $published
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Review extends Model
{
    use Recommendable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }

    /**
     * Published, has a cover, its shop is open, and not written by the viewer.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecommendable(Builder $query, Context $context): void
    {
        $query->where('reviews.published', true)
            ->whereNotNull('reviews.cover_url')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('shops')
                ->whereColumn('shops.id', 'reviews.shop_id')
                ->where('shops.is_opening', true))
            ->when($context->userId, fn (Builder $q, int|string $userId) => $q->where('reviews.user_id', '<>', $userId));
    }

    /** @return array<string, mixed> */
    public function toRecommendationCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'shop_id' => $this->shop_id,
            'writer_id' => $this->user_id,
            'cover_url' => $this->cover_url,
            'published_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
