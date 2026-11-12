<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Sources;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;
use SolutionForest\Recommender\Examples\EventsAdvanced\Support\JoinPreferences;

/** Prefer upcoming events from hosts / parties this user joins most. */
class FavoriteHostSource implements CandidateSource
{
    public function candidates(Context $context, int $limit): array
    {
        if (! $context->userId) {
            return [];
        }

        $favoriteHosts = JoinPreferences::hosts($context->userId);

        if ($favoriteHosts->isEmpty()) {
            return [];
        }

        $events = Event::query()
            ->recommendable($context->now(), $context->userId)
            ->whereIn('host_id', $favoriteHosts->keys())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get(['id', 'host_id']);

        return Math::scoreInOrder(
            $events,
            fn (Event $event) => $favoriteHosts[$event->host_id] ?? 0.1,
        );
    }
}
