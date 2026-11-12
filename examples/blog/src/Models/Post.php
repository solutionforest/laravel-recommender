<?php

namespace SolutionForest\Recommender\Examples\Blog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\Recommendable;
use SolutionForest\Recommender\Support\Context;

/**
 * @property int $id
 * @property int $author_id
 * @property string $title
 * @property bool $published
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Post extends Model
{
    use Recommendable; // forget cached cards on save / delete

    protected $guarded = [];

    protected $casts = ['published' => 'boolean'];

    /**
     * What may be recommended: published, and never the viewer's own posts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecommendable(Builder $query, Context $context): void
    {
        $query->where('published', true)
            ->when($context->userId, fn (Builder $q, int|string $userId) => $q->where('author_id', '<>', $userId));
    }

    /**
     * The JSON card (cached and shared by every viewer).
     *
     * @return array<string, mixed>
     */
    public function toRecommendationCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author_id' => $this->author_id,
            'published_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
