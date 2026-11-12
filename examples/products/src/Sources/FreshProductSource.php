<?php

namespace SolutionForest\Recommender\Examples\Products\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\Products\Models\Product;

class FreshProductSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        $now = $context->now();
        $rows = Product::query()
            ->where('published', true)
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
