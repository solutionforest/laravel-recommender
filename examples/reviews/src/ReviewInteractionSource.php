<?php

namespace SolutionForest\Recommender\Examples\Reviews;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Interaction;

class ReviewInteractionSource implements InteractionSource
{
    private const TABLES = [
        'review_likes' => 'like',
        'review_views' => 'view',
    ];

    private const USER_RANGE = 500;

    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        foreach (self::TABLES as $table => $event) {
            $rows = DB::table($table)
                ->where('created_at', '>', $from)
                ->where('created_at', '<=', $to)
                ->select(['id', 'user_id', 'review_id', 'created_at']);

            foreach ($rows->lazyById(1000) as $row) {
                yield new Interaction((int) $row->user_id, (int) $row->review_id, $event, CarbonImmutable::parse($row->created_at));
            }
        }
    }

    public function userHistories(DateTimeInterface $since, int $perUser): iterable
    {
        $maxUserId = (int) DB::table('review_likes')->max('user_id');

        for ($start = 1; $start <= $maxUserId; $start += self::USER_RANGE) {
            $events = $this->likes(fn ($q) => $q->whereBetween('user_id', [$start, $start + self::USER_RANGE - 1]), $since);

            foreach ($events->groupBy('user_id') as $userId => $rows) {
                yield (int) $userId => array_values($rows->sortByDesc('created_at')->pluck('review_id')
                    ->map(fn ($id) => (int) $id)->unique()->take($perUser)->all());
            }
        }
    }

    public function recentItems(int|string $userId, int $limit): array
    {
        return array_values($this->likes(fn ($q) => $q->where('user_id', $userId)->orderByDesc('created_at')->limit($limit))
            ->sortByDesc('created_at')->pluck('review_id')
            ->map(fn ($id) => (int) $id)->unique()->take($limit)->all());
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder  $scope
     * @return Collection<int, object{user_id: mixed, review_id: mixed, created_at: mixed}>
     */
    private function likes(callable $scope, ?DateTimeInterface $since = null): Collection
    {
        $query = $scope(DB::table('review_likes'))->when($since, fn ($q) => $q->where('created_at', '>=', $since));

        return $query->get(['user_id', 'review_id', 'created_at']);
    }
}
