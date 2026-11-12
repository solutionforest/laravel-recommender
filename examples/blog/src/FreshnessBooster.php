<?php

namespace SolutionForest\Recommender\Examples\Blog;

use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Support\Context;

/**
 * Prefer newer items among the *already blended* candidates.
 *
 * Lab lesson: keep this weight small (≈0.05–0.10) for “for you” feeds.
 * A large freshness weight makes every cohort see the same newest cards
 * (dog vs cat owners look identical). See README “Fine-tuning weights”.
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
