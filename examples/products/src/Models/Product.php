<?php

namespace SolutionForest\Recommender\Examples\Products\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\Recommendable;
use SolutionForest\Recommender\Support\Context;

/**
 * @property int $id
 * @property int $seller_id
 * @property string $title
 * @property bool $published
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Product extends Model
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
     * Published, and not the viewer's own listings.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecommendable(Builder $query, Context $context): void
    {
        $query->where('published', true)
            ->when($context->userId, fn (Builder $q, int|string $userId) => $q->where('seller_id', '<>', $userId));
    }

    /** @return array<string, mixed> */
    public function toRecommendationCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'seller_id' => $this->seller_id,
            'published_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
