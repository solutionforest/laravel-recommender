<?php

namespace SolutionForest\Recommender\Examples\Reviews\Sources;

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\Reviews\Models\Review;

/**
 * Custom product rule: prefer reviews written by users who share the viewer's pet type.
 * Swap pet_type_id for category / district / breed in a real app.
 */
class PetTypeMatchSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        if (! $context->userId) {
            return [];
        }

        $types = DB::table('user_pets')
            ->where('user_id', $context->userId)
            ->whereNotNull('pet_type_id')
            ->distinct()
            ->pluck('pet_type_id')
            ->all();

        if ($types === []) {
            return [];
        }

        $now = $context->now();
        $rows = Review::query()
            ->select(['reviews.id', 'reviews.created_at'])
            ->join('user_pets', 'user_pets.user_id', '=', 'reviews.user_id')
            ->whereIn('user_pets.pet_type_id', $types)
            ->where('reviews.published', true)
            ->whereNotNull('reviews.cover_url')
            ->where('reviews.user_id', '<>', $context->userId)
            ->orderByDesc('reviews.created_at')
            ->limit($limit)
            ->distinct()
            ->get();

        $scores = [];
        foreach ($rows as $row) {
            $ageDays = max(0, $row->created_at?->diffInDays($now) ?? 90);
            $scores[$row->id] = exp(-$ageDays / 30);
        }

        return $scores;
    }
}
