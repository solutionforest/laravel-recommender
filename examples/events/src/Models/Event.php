<?php

namespace SolutionForest\Recommender\Examples\Events\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'starts_at' => 'datetime',
        ];
    }
}
