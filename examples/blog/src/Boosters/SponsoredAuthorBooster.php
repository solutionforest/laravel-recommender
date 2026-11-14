<?php

namespace SolutionForest\Recommender\Examples\Blog\Boosters;

use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Value;
use SolutionForest\Recommender\Examples\Blog\Models\Post;

/**
 * Boost candidates written by sponsored authors (`sponsored_author_ids`).
 *
 * Booster only re-scores existing candidates — combine with
 * {@see SponsoredAuthorSource} to also inject them into the pool.
 */
class SponsoredAuthorBooster implements Booster
{
    public function boost(Context $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sponsored = array_flip(array_map(
            fn ($id) => Value::int($id),
            (array) $context->feed->get('sponsored_author_ids', [])
        ));

        if ($sponsored === []) {
            return [];
        }

        $out = [];
        foreach (Post::query()->whereIn('id', $ids)->get(['id', 'author_id']) as $row) {
            $out[$row->id] = isset($sponsored[(int) $row->author_id]) ? 1.0 : 0.0;
        }

        return $out;
    }
}
