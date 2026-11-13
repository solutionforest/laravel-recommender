<?php

namespace SolutionForest\Recommender\Examples\Events\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\Recommendable;
use SolutionForest\Recommender\Support\Context;

/**
 * @property int $id
 * @property int $host_id
 * @property string $title
 * @property bool $published
 * @property \Illuminate\Support\Carbon|null $starts_at
 */
class Event extends Model
{
    use Recommendable;

    protected $guarded = [];

    protected $casts = [
            'published' => 'boolean',
            'starts_at' => 'datetime',
    ];

    /**
     * Published, upcoming (or started within a day / undated), and not
     * hosted by the viewer.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecommendable(Builder $query, Context $context): void
    {
        $query->where('published', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', $context->now()->subDay()))
            ->when($context->userId, fn (Builder $q, int|string $userId) => $q->where('host_id', '<>', $userId));
    }

    /** @return array<string, mixed> */
    public function toRecommendationCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'host_id' => $this->host_id,
            'starts_at' => $this->starts_at?->toIso8601String(),
        ];
    }
}
