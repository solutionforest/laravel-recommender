<?php

namespace SolutionForest\Recommender\Examples\Products\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }
}
