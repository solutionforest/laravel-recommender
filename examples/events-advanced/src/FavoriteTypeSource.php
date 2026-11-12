<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced;

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;

/**
 * Prefer event types the user has joined most often.
 *
 * join_count(type) from meetup_event_joins → top types → upcoming events of those types.
 */
class FavoriteTypeSource implements CandidateSource
{
    private const TOP_TYPES = 3;

    public function candidates(Context $context, int $limit): array
    {
        if (! $context->userId) {
            return [];
        }

        $topTypes = DB::table('meetup_event_joins as j')
            ->join('meetup_events as e', 'e.id', '=', 'j.event_id')
            ->where('j.user_id', $context->userId)
            ->select('e.type_id', DB::raw('count(*) as joins'))
            ->groupBy('e.type_id')
            ->orderByDesc('joins')
            ->limit(self::TOP_TYPES)
            ->get();

        if ($topTypes->isEmpty()) {
            return [];
        }

        $typeWeight = [];
        foreach ($topTypes as $rank => $row) {
            $typeWeight[(int) $row->type_id] = ((float) $row->joins) / (1 + $rank);
        }

        $now = $context->now();
        $rows = MeetupEvent::query()
            ->whereIn('type_id', array_keys($typeWeight))
            ->where('published', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '>=', $now->subDay());
            })
            ->where('host_id', '<>', $context->userId)
            ->orderBy('starts_at')
            ->limit($limit)
            ->get(['id', 'type_id', 'starts_at']);

        $scores = [];
        foreach ($rows as $i => $row) {
            $affinity = $typeWeight[(int) $row->type_id] ?? 0.1;
            $scores[$row->id] = $affinity / (1 + $i * 0.05);
        }

        return $scores;
    }
}
