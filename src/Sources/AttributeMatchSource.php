<?php

namespace SolutionForest\Recommender\Sources;

use SolutionForest\Recommender\Content\AttributeMatcher;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\Context;

/**
 * "More with the same category / tags as what you just interacted with",
 * from the items' own attributes (the feed's `match` config). Unlike
 * CoInteractionSource it also finds items nobody has interacted with yet,
 * so new items get shown. Newest candidates first, at most `match_pool`.
 *
 *     'sources' => [
 *         CoInteractionSource::class => 0.5,
 *         AttributeMatchSource::class => 0.2,
 *         TrendingSource::class => 0.3,
 *     ],
 *
 * Guests get nothing from it (no recent items).
 */
final class AttributeMatchSource implements CandidateSource
{
    public function __construct(
        private readonly AttributeMatcher $matcher,
        private readonly Bindings $bindings,
    ) {
    }

    public function candidates(Context $context, int $limit): array
    {
        if ($context->isGuest() || ! $this->matcher->configured($context->feed)) {
            return [];
        }

        $interactions = $this->bindings->interactions($context->feed);
        if ($interactions === null || $context->userId === null) {
            return [];
        }

        $recent = $interactions->recentItems($context->userId, $context->feed->int('recent_items', 20));

        return $this->matcher->scores($context->feed, $recent, $context, $limit);
    }
}
