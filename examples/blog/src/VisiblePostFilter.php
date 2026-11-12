<?php

namespace SolutionForest\Recommender\Examples\Blog;

use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Support\Context;

/**
 * Step 2 — business rules. One query for all candidates of a build.
 */
class VisiblePostFilter implements ItemFilter
{
    public function allowed(Context $context, array $ids): array
    {
        return Post::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->when($context->userId, fn ($q) => $q->where('author_id', '<>', $context->userId)) // not my own posts
            ->pluck('id')
            ->all();
    }
}
