<?php

namespace SolutionForest\Recommender\Examples\Events;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Interaction;

/**
 * Three signals: view (weak), like, join/RSVP (strongest).
 */
class EventInteractionSource implements InteractionSource
{
    private const TABLES = [
        'event_views' => 'view',
        'event_likes' => 'like',
        'event_joins' => 'join',
    ];

    private const USER_RANGE = 500;

    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        foreach (self::TABLES as $table => $event) {
            $rows = DB::table($table)
                ->where('created_at', '>', $from)
                ->where('created_at', '<=', $to)
                ->select(['id', 'user_id', 'event_id', 'created_at']);

            foreach ($rows->lazyById(1000) as $row) {
                yield new Interaction((int) $row->user_id, (int) $row->event_id, $event, CarbonImmutable::parse($row->created_at));
            }
        }
    }

    public function userHistories(DateTimeInterface $since, int $perUser): iterable
    {
        // Co-interaction from strong actions only (like + join).
        $maxUserId = (int) max(
            (int) DB::table('event_likes')->max('user_id'),
            (int) DB::table('event_joins')->max('user_id'),
        );

        for ($start = 1; $start <= $maxUserId; $start += self::USER_RANGE) {
            $rows = $this->strongEvents(fn ($q) => $q->whereBetween('user_id', [$start, $start + self::USER_RANGE - 1]), $since);

            foreach ($rows->groupBy('user_id') as $userId => $group) {
                yield (int) $userId => array_values($group->sortByDesc('created_at')->pluck('event_id')
                    ->map(fn ($id) => (int) $id)->unique()->take($perUser)->all());
            }
        }
    }

    public function recentItems(int|string $userId, int $limit): array
    {
        return array_values($this->strongEvents(fn ($q) => $q->where('user_id', $userId)->orderByDesc('created_at')->limit($limit))
            ->sortByDesc('created_at')->pluck('event_id')
            ->map(fn ($id) => (int) $id)->unique()->take($limit)->all());
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder  $scope
     * @return Collection<int, object{user_id: mixed, event_id: mixed, created_at: mixed}>
     */
    private function strongEvents(callable $scope, ?DateTimeInterface $since = null): Collection
    {
        $all = collect();
        foreach (['event_likes', 'event_joins'] as $table) {
            $query = $scope(DB::table($table))->when($since, fn ($q) => $q->where('created_at', '>=', $since));
            $all = $all->concat($query->get(['user_id', 'event_id', 'created_at']));
        }

        return $all;
    }
}
