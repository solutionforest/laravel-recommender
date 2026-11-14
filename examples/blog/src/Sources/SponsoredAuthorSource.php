<?php

namespace SolutionForest\Recommender\Examples\Blog\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Value;
use SolutionForest\Recommender\Examples\Blog\Models\Post;

/**
 * Inject posts whose author is on a paid / campaign list
 * (config key `sponsored_author_ids`). Pair with {@see SponsoredAuthorBooster}
 * so injected items also rank higher. Diversity caps still limit how many
 * appear per page. The same pattern works for any owner column (brand, shop).
 */
class SponsoredAuthorSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        $ids = array_values(array_unique(array_map(
            fn ($id) => Value::int($id),
            (array) $context->feed->get('sponsored_author_ids', [])
        )));

        if ($ids === []) {
            return [];
        }

        $rank = array_flip($ids);
        $rows = Post::query()
            ->where('published', true)
            ->whereIn('author_id', $ids)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'author_id']);

        $scores = [];
        foreach ($rows as $row) {
            $scores[$row->id] = 1 / (1 + 0.1 * ($rank[(int) $row->author_id] ?? 99));
        }

        return $scores;
    }
}
