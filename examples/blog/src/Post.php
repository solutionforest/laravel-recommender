<?php

namespace SolutionForest\Recommender\Examples\Blog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $author_id
 * @property string $title
 * @property bool $published
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Post extends Model
{
    protected $guarded = [];

    protected $casts = ['published' => 'boolean'];
}
