<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Scoring\Math;

/**
 * “What has this user joined most?” — types and hosting parties.
 */
final class JoinPreferences
{
    /**
     * Favorite event types, strongest first.
     *
     * @return Collection<int, float> type_id => affinity
     */
    public static function types(int|string $userId, int $take = 3): Collection
    {
        return self::ranked('e.type_id', $userId, $take);
    }

    /**
     * Favorite hosts / parties, strongest first (never the user themselves).
     *
     * @return Collection<int, float> host_id => affinity
     */
    public static function hosts(int|string $userId, int $take = 3): Collection
    {
        return self::ranked('e.host_id', $userId, $take, excludeSelf: true);
    }

    /**
     * @return Collection<int, float>
     */
    private static function ranked(string $column, int|string $userId, int $take, bool $excludeSelf = false): Collection
    {
        $rows = DB::table('event_joins as j')
            ->join('events as e', 'e.id', '=', 'j.event_id')
            ->where('j.user_id', $userId)
            ->when($excludeSelf, fn ($q) => $q->where('e.host_id', '<>', $userId))
            ->select($column.' as key', DB::raw('count(*) as joins'))
            ->groupBy('key')
            ->orderByDesc('joins')
            ->limit($take)
            ->get();

        return $rows->values()->mapWithKeys(
            fn ($row, int $rank) => [(int) $row->key => Math::fromAffinity((float) $row->joins, $rank, step: 1.0)]
        );
    }
}
