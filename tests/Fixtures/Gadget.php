<?php

namespace SolutionForest\Recommender\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\Recommendable;
use SolutionForest\Recommender\Support\Context;

/**
 * Catalog item for the attribute-match tests: a category, a price and tags.
 *
 * @property int $id
 * @property int $category_id
 * @property float $price
 * @property bool $published
 */
class Gadget extends Model
{
    use Recommendable;

    protected $guarded = [];

    protected $casts = ['published' => 'boolean', 'price' => 'float'];

    /** @param  Builder<self>  $query */
    public function scopeRecommendable(Builder $query, Context $context): void
    {
        $query->where('gadgets.published', true);
    }

    /** @return array<string, mixed> */
    public function toRecommendationCard(): array
    {
        return ['id' => $this->id, 'category_id' => $this->category_id];
    }
}
