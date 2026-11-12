<?php

namespace SolutionForest\Recommender\Examples\Blog\Boosters;

use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\Blog\Models\Post;

/**
 * Prefer newer items among the *already blended* candidates.
 *
 * Lab lesson: inspiration feeds should NOT optimise for “latest first”.
 * Prefer omitting this booster. If used, keep weight tiny (≈0.05) —
 * a large weight makes every cohort see the same newest cards.
 *
 * score = exp(−ageHours / 72), then scaled so the freshest in the batch is 1.0.
 */
class FreshnessBooster implements Booster
{
    public function boost(Context $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $now = $context->now();
        $out = [];
        foreach (Post::query()->whereIn('id', $ids)->get(['id', 'created_at']) as $row) {
            $hours = max(0, $row->created_at?->diffInHours($now) ?? 0);
            $out[$row->id] = exp(-$hours / 72);
        }

        $max = $out === [] ? 0.0 : max($out);

        return $max > 0 ? array_map(fn ($v) => $v / $max, $out) : $out;
    }
}
