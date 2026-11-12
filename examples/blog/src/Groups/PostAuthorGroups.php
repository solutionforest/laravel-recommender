<?php

namespace SolutionForest\Recommender\Examples\Blog\Groups;

use SolutionForest\Recommender\Contracts\DiversityGroups;
use SolutionForest\Recommender\Examples\Blog\Models\Post;

/** Optional — lets 'diversity' => ['author' => 1] keep one post per author per page. */
class PostAuthorGroups implements DiversityGroups
{
    public function groups(array $ids): array
    {
        return Post::query()->whereIn('id', $ids)->pluck('author_id', 'id')
            ->map(fn ($author) => ['author' => $author])
            ->all();
    }
}
