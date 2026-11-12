<?php

namespace SolutionForest\Recommender\Examples\Reviews;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;

class FreshReviewSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        $now = $context->now();
        $rows = Review::query()
            ->where('published', true)
            ->whereNotNull('cover_url')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'created_at']);

        $scores = [];
        foreach ($rows as $row) {
            $ageDays = max(0, $row->created_at?->diffInDays($now) ?? 30);
            $scores[$row->id] = exp(-$ageDays / 14);
        }

        return $scores;
    }
}
