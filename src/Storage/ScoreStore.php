<?php

namespace SolutionForest\Recommender\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Ids;

/**
 * rec_item_scores: one row per item, updated incrementally.
 */
final class ScoreStore
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * Merge freshly aggregated deltas into the stored rows.
     *
     * @param  array<string, array{hot: ?float, positive: int, exposure: int, last: ?\DateTimeInterface}>  $deltas  item id => delta
     */
    public function merge(string $feed, array $deltas, int $chunk = 500): int
    {
        $written = 0;
        foreach (array_chunk($deltas, max(1, $chunk), true) as $part) {
            $ids = array_map('strval', array_keys($part));
            $existing = $this->connection()->table('rec_item_scores')
                ->where('feed', $feed)
                ->whereIn('item_id', $ids)
                ->get(['item_id', 'hot', 'positive', 'exposure', 'last_event_at'])
                ->keyBy('item_id');

            $rows = [];
            $now = now();
            foreach ($part as $id => $delta) {
                $old = $existing->get((string) $id);
                $hot = $old?->hot === null ? null : (float) $old->hot;
                if ($delta['hot'] !== null) {
                    $hot = Math::logAddExp($hot, $delta['hot']);
                }
                $last = $delta['last'] ?? null;
                $oldLast = $old?->last_event_at ? \Carbon\Carbon::parse($old->last_event_at) : null;
                if ($oldLast && (! $last || $oldLast->greaterThan($last))) {
                    $last = $oldLast;
                }

                $rows[] = [
                    'feed' => $feed,
                    'item_id' => (string) $id,
                    'hot' => $hot,
                    'positive' => (int) ($old->positive ?? 0) + $delta['positive'],
                    'exposure' => (int) ($old->exposure ?? 0) + $delta['exposure'],
                    'last_event_at' => $last ? \Carbon\Carbon::instance($last)->toDateTimeString() : null,
                    'updated_at' => $now,
                ];
            }

            $this->connection()->table('rec_item_scores')->upsert(
                $rows,
                ['feed', 'item_id'],
                ['hot', 'positive', 'exposure', 'last_event_at', 'updated_at'],
            );
            $written += count($rows);
        }

        return $written;
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function transaction(\Closure $callback): mixed
    {
        return $this->connection()->transaction($callback);
    }

    /**
     * Delete rows of items without any event since $before, in bulk chunks
     * by primary key (short transactions, no long table lock).
     */
    public function prune(string $feed, \DateTimeInterface $before, int $chunk = 5000): int
    {
        $deleted = 0;
        $cutoff = \Carbon\Carbon::instance($before)->toDateTimeString();

        do {
            $ids = $this->connection()->table('rec_item_scores')
                ->where('feed', $feed)
                ->where(function ($q) use ($cutoff) {
                    $q->where('last_event_at', '<', $cutoff)->orWhereNull('last_event_at');
                })
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $deleted += $this->connection()->table('rec_item_scores')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $chunk);

        return $deleted;
    }

    /**
     * Delete every score row of one feed, in bulk chunks by primary key.
     */
    public function deleteFeed(string $feed, int $chunk = 5000): int
    {
        $deleted = 0;

        do {
            $ids = $this->connection()->table('rec_item_scores')
                ->where('feed', $feed)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $deleted += $this->connection()->table('rec_item_scores')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $chunk);

        return $deleted;
    }

    public function count(string $feed): int
    {
        return (int) $this->connection()->table('rec_item_scores')->where('feed', $feed)->count();
    }

    /**
     * Top items by decayed popularity.
     *
     * @return array<int|string, float> id => score in (0, 1], best first
     */
    public function hottest(string $feed, int $limit): array
    {
        $rows = $this->connection()->table('rec_item_scores')
            ->where('feed', $feed)
            ->whereNotNull('hot')
            ->orderByDesc('hot')
            ->limit($limit)
            ->get(['item_id', 'hot']);

        if ($rows->isEmpty()) {
            return [];
        }

        $max = (float) $rows->first()->hot;
        $out = [];
        foreach ($rows as $row) {
            $out[Ids::normalize($row->item_id)] = exp((float) $row->hot - $max);
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, array{positive: int, exposure: int}>
     */
    public function counts(string $feed, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 1000) as $part) {
            $rows = $this->connection()->table('rec_item_scores')
                ->where('feed', $feed)
                ->whereIn('item_id', array_map('strval', $part))
                ->get(['item_id', 'positive', 'exposure']);
            foreach ($rows as $row) {
                $out[Ids::normalize($row->item_id)] = [
                    'positive' => (int) $row->positive,
                    'exposure' => (int) $row->exposure,
                ];
            }
        }

        return $out;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection(config('recommender.database_connection'));
    }
}
