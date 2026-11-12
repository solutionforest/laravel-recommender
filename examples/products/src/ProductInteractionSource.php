<?php

namespace SolutionForest\Recommender\Examples\Products;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Interaction;

/**
 * Three event types — view / like / purchase. Add more TABLES entries anytime.
 */
class ProductInteractionSource implements InteractionSource
{
    private const TABLES = [
        'product_views' => 'view',
        'product_likes' => 'like',
        'product_purchases' => 'purchase',
    ];

    private const USER_RANGE = 500;

    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        foreach (self::TABLES as $table => $event) {
            $rows = DB::table($table)
                ->where('created_at', '>', $from)
                ->where('created_at', '<=', $to)
                ->select(['id', 'user_id', 'product_id', 'created_at']);

            foreach ($rows->lazyById(1000) as $row) {
                yield new Interaction((int) $row->user_id, (int) $row->product_id, $event, CarbonImmutable::parse($row->created_at));
            }
        }
    }

    public function userHistories(DateTimeInterface $since, int $perUser): iterable
    {
        // Neighbours: prefer strong signals (like + purchase), not every view.
        $maxUserId = (int) max(
            (int) DB::table('product_likes')->max('user_id'),
            (int) DB::table('product_purchases')->max('user_id'),
        );

        for ($start = 1; $start <= $maxUserId; $start += self::USER_RANGE) {
            $events = $this->strongEvents(fn ($q) => $q->whereBetween('user_id', [$start, $start + self::USER_RANGE - 1]), $since);

            foreach ($events->groupBy('user_id') as $userId => $rows) {
                yield (int) $userId => array_values($rows->sortByDesc('created_at')->pluck('product_id')
                    ->map(fn ($id) => (int) $id)->unique()->take($perUser)->all());
            }
        }
    }

    public function recentItems(int|string $userId, int $limit): array
    {
        return array_values($this->strongEvents(fn ($q) => $q->where('user_id', $userId)->orderByDesc('created_at')->limit($limit))
            ->sortByDesc('created_at')->pluck('product_id')
            ->map(fn ($id) => (int) $id)->unique()->take($limit)->all());
    }

    /**
     * @param  callable(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder  $scope
     * @return Collection<int, object{user_id: mixed, product_id: mixed, created_at: mixed}>
     */
    private function strongEvents(callable $scope, ?DateTimeInterface $since = null): Collection
    {
        $all = collect();
        foreach (['product_likes', 'product_purchases'] as $table) {
            $query = $scope(DB::table($table))->when($since, fn ($q) => $q->where('created_at', '>=', $since));
            $all = $all->concat($query->get(['user_id', 'product_id', 'created_at']));
        }

        return $all;
    }
}
