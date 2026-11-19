<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\Recommendable;

class Post extends Model
{
    use Recommendable;

    protected $guarded = [];

    public function scopeRecommendable($query, $context)    // what may be shown
    {
        $query->where('published', true);
    }

    public function toRecommendationCard(): array           // the JSON per item
    {
        return ['id' => $this->id, 'title' => $this->title, 'topic' => $this->topic];
    }
}
