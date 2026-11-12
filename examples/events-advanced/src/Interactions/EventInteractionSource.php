<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Interactions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Interaction;

/**
 * Stream view / like / comment / join rows from your tables.
 *
 * Strong actions (like, comment, join) drive neighbours; views are exposure only.
 */
class EventInteractionSource implements InteractionSource
{
    private const SIGNALS = [
        'event_views' => 'view',
        'event_likes' => 'like',
        'event_comments' => 'comment',
        'event_joins' => 'join',
    ];

    private const STRONG = ['event_likes', 'event_comments', 'event_joins'];

    private const CHUNK = 500;

    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        foreach (self::SIGNALS as $table => $name) {
            $query = DB::table($table)
                ->where('created_at', '>', $from)
                ->where('created_at', '<=', $to)
                ->select(['id', 'user_id', 'event_id', 'created_at']);

            foreach ($query->lazyById(1000) as $row) {
                yield new Interaction(
                    (int) $row->user_id,
                    (int) $row->event_id,
                    $name,
                    CarbonImmutable::parse($row->created_at),
                );
            }
        }
    }

    public function userHistories(DateTimeInterface $since, int $perUser): iterable
    {
        $lastUser = (int) collect(self::STRONG)->max(fn ($table) => (int) DB::table($table)->max('user_id'));

        for ($from = 1; $from <= $lastUser; $from += self::CHUNK) {
            $to = $from + self::CHUNK - 1;

            foreach ($this->strongActions(since: $since, users: [$from, $to])->groupBy('user_id') as $userId => $rows) {
                yield (int) $userId => $this->uniqueEventIds($rows, $perUser);
            }
        }
    }

    public function recentItems(int|string $userId, int $limit): array
    {
        return $this->uniqueEventIds(
            $this->strongActions(userId: $userId)->take($limit),
            $limit,
        );
    }

    /**
     * @param  array{0: int, 1: int}|null  $users
     * @return Collection<int, object{user_id: mixed, event_id: mixed, created_at: mixed}>
     */
    private function strongActions(
        ?DateTimeInterface $since = null,
        int|string|null $userId = null,
        ?array $users = null,
    ): Collection {
        return collect(self::STRONG)->flatMap(function (string $table) use ($since, $userId, $users) {
            return DB::table($table)
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId)->orderByDesc('created_at'))
                ->when($users, fn ($q) => $q->whereBetween('user_id', $users))
                ->get(['user_id', 'event_id', 'created_at']);
        });
    }

    /**
     * @param  Collection<int, object{event_id: mixed, created_at: mixed}>  $rows
     * @return list<int>
     */
    private function uniqueEventIds(Collection $rows, int $limit): array
    {
        return $rows
            ->sortByDesc('created_at')
            ->pluck('event_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }
}
