<?php

namespace SolutionForest\Recommender\Examples\Blog;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $guarded = [];

    protected $casts = ['published' => 'boolean'];
}
