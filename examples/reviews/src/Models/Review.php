<?php

namespace SolutionForest\Recommender\Examples\Reviews\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }
}
