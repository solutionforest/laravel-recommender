<?php

namespace SolutionForest\Recommender\Examples\Simple\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\Recommendable;
use SolutionForest\Recommender\Support\Context;

/**
 * @property int $id
 * @property int $author_id
 * @property string $title
 * @property bool $published
 */
class Article extends Model
{
    use Recommendable; // optional: refreshes cached cards on save / delete

    protected $guarded = [];

    protected $casts = ['published' => 'boolean'];

    /**
     * Which articles may be recommended. $context->userId is the viewer
     * (null for guests and for the shared card cache).
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecommendable(Builder $query, Context $context): void
    {
        $query->where('published', true)
            ->when($context->userId, fn (Builder $q, int|string $userId) => $q->where('author_id', '<>', $userId));
    }

    /**
     * The JSON returned for each item (optional — defaults to toArray()).
     * Shared by every viewer, so no viewer-specific fields.
     *
     * @return array<string, mixed>
     */
    public function toRecommendationCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author_id' => $this->author_id,
        ];
    }
}
