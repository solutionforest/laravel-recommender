<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;
use SolutionForest\Recommender\Examples\EventsAdvanced\Support\JoinPreferences;

/** Prefer upcoming events of the types this user joins most. */
class FavoriteTypeSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        if (! $context->userId) {
            return [];
        }

        $favoriteTypes = JoinPreferences::types($context->userId);

        if ($favoriteTypes->isEmpty()) {
            return [];
        }

        $events = Event::query()
            ->recommendable($context)
            ->whereIn('type_id', $favoriteTypes->keys())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get(['id', 'type_id']);

        return Math::scoreInOrder(
            $events,
            fn (Event $event) => $favoriteTypes[$event->type_id] ?? 0.1,
        );
    }
}
