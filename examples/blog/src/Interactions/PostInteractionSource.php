<?php

namespace SolutionForest\Recommender\Examples\Blog\Interactions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Interaction;

/**
 * Step 1 — tell the package where your likes/views already live.
 * Every method reads in bulk (lazyById / whereBetween ranges), never per row.
 */
class PostInteractionSource implements InteractionSource
{
    private const TABLES = ['post_likes' => 'like', 'post_views' => 'view'];

    private const USER_RANGE = 500;

    /** Used every 10 minutes by recommender:update-scores (a small time window). */
    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        foreach (self::TABLES as $table => $event) {
            $rows = DB::table($table)
                ->where('created_at', '>', $from)
                ->where('created_at', '<=', $to)
                ->select(['id', 'user_id', 'post_id', 'created_at']);

            foreach ($rows->lazyById(1000) as $row) {
                yield new Interaction((int) $row->user_id, (int) $row->post_id, $event, CarbonImmutable::parse($row->created_at));
            }
        }
    }

    /** Used nightly by recommender:build-neighbours. Streams users in id ranges. */
    public function userHistories(DateTimeInterface $since, int $perUser): iterable
    {
        $maxUserId = (int) max(DB::table('post_likes')->max('user_id'), DB::table('post_views')->max('user_id'));

        for ($start = 1; $start <= $maxUserId; $start += self::USER_RANGE) {
            $events = $this->events(fn ($q) => $q->whereBetween('user_id', [$start, $start + self::USER_RANGE - 1]), $since);

            foreach ($events->groupBy('user_id') as $userId => $rows) {
                yield (int) $userId => array_values($rows->sortByDesc('created_at')->pluck('post_id')
                    ->map(fn ($id) => (int) $id)->unique()->take($perUser)->all());
            }
        }
    }

    /** Used when a user's feed is built: their latest posts. */
    public function recentItems(int|string $userId, int $limit): array
    {
        return array_values($this->events(fn ($q) => $q->where('user_id', $userId)->orderByDesc('created_at')->limit($limit))
            ->sortByDesc('created_at')->pluck('post_id')
            ->map(fn ($id) => (int) $id)->unique()->take($limit)->all());
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder  $scope
     * @return Collection<int, object{user_id: mixed, post_id: mixed, created_at: mixed}>
     */
    private function events(callable $scope, ?DateTimeInterface $since = null): Collection
    {
        $all = collect();
        foreach (array_keys(self::TABLES) as $table) {
            $query = $scope(DB::table($table))->when($since, fn ($q) => $q->where('created_at', '>=', $since));
            $all = $all->concat($query->get(['user_id', 'post_id', 'created_at']));
        }

        return $all;
    }
}
