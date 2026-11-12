<?php

namespace SolutionForest\Recommender\Examples\Blog\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\Blog\Models\Post;

/**
 * Inject posts whose author (or shop) is on a paid / campaign list.
 *
 * Lab pattern: config key `sponsored_author_ids` (or rename to shop ids in
 * a reviews feed). Pair with {@see SponsoredShopBooster} so injected items
 * also rank higher. Diversity caps still limit how many appear per page.
 *
 * This blog example treats "sponsor" as author_id for simplicity.
 */
class SponsoredShopSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            (array) $context->feed->get('sponsored_author_ids', $context->feed->get('sponsored_shop_ids', []))
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
