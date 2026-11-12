<?php

namespace SolutionForest\Recommender\Examples\Blog\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\Blog\Models\Post;

/**
 * Optional — your own signal. New posts get exposure before anyone liked them.
 * One indexed query with a LIMIT.
 */
class FreshPostSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        $now = $context->now();

        return Post::query()
            ->where('published', true)
            ->where('created_at', '>=', $now->subDays(7))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'created_at'])
            ->mapWithKeys(fn (Post $post) => [$post->id => exp(-abs($now->diffInHours($post->created_at)) / 72)])
            ->all();
    }
}
