<?php

namespace SolutionForest\Recommender\Examples\Blog;

use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Support\Context;

/**
 * Boost candidates that belong to sponsored authors/shops.
 *
 * Booster only re-scores existing candidates — combine with
 * {@see SponsoredShopSource} to also inject them into the pool.
 */
class SponsoredShopBooster implements Booster
{
    public function boost(Context $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sponsored = array_flip(array_map(
            'intval',
            (array) $context->feed->get('sponsored_author_ids', $context->feed->get('sponsored_shop_ids', []))
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
