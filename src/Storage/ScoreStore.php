<?php

namespace SolutionForest\Recommender\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Support\Value;

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
        ksort($deltas, SORT_STRING); // same row order as request-path writes: no lock-order deadlocks
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
                $hot = $old?->hot === null ? null : Value::float($old->hot);
                if ($delta['hot'] !== null) {
                    $hot = Math::logAddExp($hot, $delta['hot']);
                }
                $last = $delta['last'] ?? null;
                $oldLast = $old?->last_event_at ? \Carbon\Carbon::parse(Value::string($old->last_event_at)) : null;
                if ($oldLast && (! $last || $oldLast->greaterThan($last))) {
                    $last = $oldLast;
                }

                $rows[] = [
                    'feed' => $feed,
                    'item_id' => (string) $id,
                    'hot' => $hot,
                    'positive' => Value::int($old->positive ?? 0) + $delta['positive'],
                    'exposure' => Value::int($old->exposure ?? 0) + $delta['exposure'],
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
                ->where(function (Builder $q) use ($cutoff) {
                    $q->where('last_event_at', '<', $cutoff)->orWhereNull('last_event_at');
                })
                // Still being shown (impressions): keep.
                ->where(function (Builder $q) use ($cutoff) {
                    $q->where('last_shown_at', '<', $cutoff)->orWhereNull('last_shown_at');
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
     * Zero one feed's event-derived scores (hot, counters, daily counters) so
     * they can be replayed. Impressions are kept: they cannot be replayed
     * from the app's tables. Bulk chunks by primary key.
     */
    public function resetFeed(string $feed, int $chunk = 5000): int
    {
        $reset = 0;
        foreach (['rec_item_scores' => ['hot' => null, 'positive' => 0, 'exposure' => 0, 'last_event_at' => null], 'rec_item_daily' => ['positive' => 0, 'exposure' => 0]] as $table => $values) {
            $after = 0;
            do {
                $ids = $this->connection()->table($table)
                    ->where('feed', $feed)
                    ->where('id', '>', $after)
                    ->orderBy('id')
                    ->limit($chunk)
                    ->pluck('id')
                    ->all();

                if ($ids !== []) {
                    $count = $this->connection()->table($table)->whereIn('id', $ids)->update($values);
                    $reset += $table === 'rec_item_scores' ? $count : 0;
                    $after = Value::int(end($ids));
                }
            } while (count($ids) === $chunk);
        }

        return $reset;
    }

    /**
     * Count one impression for each served item (lifetime and today).
     * Missing rows are inserted, then incremented atomically by the database,
     * so concurrent requests never lose counts. Four small statements.
     *
     * @param  list<int|string>  $ids
     */
    public function addImpressions(string $feed, array $ids, \DateTimeInterface $now): void
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === []) {
            return;
        }
        sort($ids, SORT_STRING);
        $at = \Carbon\Carbon::instance($now);
        $day = $at->toDateString();

        // Increment first: an UPDATE takes exclusive row locks in key order, so
        // concurrent requests queue instead of deadlocking. (INSERT IGNORE on
        // existing rows takes shared locks; two requests upgrading them to
        // write locks is the classic MySQL deadlock.) Only rows that do not
        // exist yet are inserted — rare once items have scores. Each statement
        // commits on its own and is retried on a deadlock / lock timeout.
        $this->incrementOrCreate('rec_item_scores', ['feed' => $feed], $ids, ['last_shown_at' => $at]);
        $this->incrementOrCreate('rec_item_daily', ['feed' => $feed, 'day' => $day], $ids);
    }

    /**
     * @param  array<string, string>  $where  fixed columns (feed, day)
     * @param  list<string>  $ids  sorted item ids
     * @param  array<string, mixed>  $extra  columns to set on increment
     */
    private function incrementOrCreate(string $table, array $where, array $ids, array $extra = []): void
    {
        $db = $this->connection();
        $query = fn (array $part) => $db->table($table)->where($where)->whereIn('item_id', $part);

        $updated = $this->retryOnDeadlock(fn () => $query($ids)->increment('impressions', 1, $extra));
        if ($updated >= count($ids)) {
            return;
        }

        $existing = [];
        foreach ($query($ids)->pluck('item_id') as $itemId) {
            $existing[Value::string($itemId)] = true;
        }
        $missing = array_values(array_filter($ids, fn (string $id) => ! isset($existing[$id])));
        if ($missing === []) {
            return; // created by another request meanwhile and already counted by our UPDATE
        }

        $this->retryOnDeadlock(fn () => $db->table($table)->insertOrIgnore(
            array_map(fn (string $id) => $where + ['item_id' => $id, 'impressions' => 0], $missing),
        ));
        $this->retryOnDeadlock(fn () => $query($missing)->increment('impressions', 1, $extra));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    private function retryOnDeadlock(\Closure $write, int $attempts = 3): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $write();
            } catch (\Illuminate\Database\QueryException $e) {
                $concurrency = in_array($e->getCode(), ['40001', '40P01', 'HY000'], true)
                    && preg_match('/deadlock|lock wait timeout|database is locked|serializ/i', $e->getMessage()) === 1;
                if (! $concurrency || $try >= $attempts) {
                    throw $e;
                }
                usleep(random_int(5_000, 25_000) * $try);
            }
        }
    }

    /**
     * Fold per-day event counters (from update-scores; runs serialised).
     *
     * @param  array<string, array<string, array{positive: int, exposure: int}>>  $daily  item id => day => counts
     */
    public function mergeDaily(string $feed, array $daily, int $chunk = 500): void
    {
        $flat = [];
        foreach ($daily as $id => $days) {
            foreach ($days as $day => $counts) {
                $flat[] = ['item_id' => (string) $id, 'day' => $day] + $counts;
            }
        }

        usort($flat, fn (array $a, array $b) => [$a['item_id'], $a['day']] <=> [$b['item_id'], $b['day']]);
        foreach (array_chunk($flat, max(1, $chunk)) as $part) {
            $existing = [];
            $query = $this->connection()->table('rec_item_daily')->where('feed', $feed)
                ->whereIn('item_id', array_values(array_unique(array_column($part, 'item_id'))))
                ->whereIn('day', array_values(array_unique(array_column($part, 'day'))))
                ->get(['item_id', 'day', 'positive', 'exposure']);
            foreach ($query as $row) {
                $existing[Value::string($row->item_id).'|'.substr(Value::string($row->day), 0, 10)] = $row;
            }

            $rows = [];
            foreach ($part as $row) {
                $old = $existing[$row['item_id'].'|'.$row['day']] ?? null;
                $rows[] = [
                    'feed' => $feed,
                    'item_id' => $row['item_id'],
                    'day' => $row['day'],
                    'positive' => Value::int($old->positive ?? 0) + $row['positive'],
                    'exposure' => Value::int($old->exposure ?? 0) + $row['exposure'],
                ];
            }
            $this->connection()->table('rec_item_daily')->upsert($rows, ['feed', 'item_id', 'day'], ['positive', 'exposure']);
        }
    }

    /**
     * Summed daily counters for some items over the last $days days (today included).
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, array{positive: int, exposure: int, impressions: int}>
     */
    public function window(string $feed, array $ids, int $days, \DateTimeInterface $now): array
    {
        $from = \Carbon\Carbon::instance($now)->subDays(max(1, $days) - 1)->toDateString();
        $out = [];
        foreach (array_chunk($ids, 1000) as $part) {
            $rows = $this->connection()->table('rec_item_daily')
                ->where('feed', $feed)
                ->whereIn('item_id', array_map('strval', $part))
                ->where('day', '>=', $from)
                ->groupBy('item_id')
                ->selectRaw('item_id, sum(positive) as positive, sum(exposure) as exposure, sum(impressions) as impressions')
                ->get();
            foreach ($rows as $row) {
                $out[Ids::normalize(Value::string($row->item_id))] = [
                    'positive' => Value::int($row->positive),
                    'exposure' => Value::int($row->exposure),
                    'impressions' => Value::int($row->impressions),
                ];
            }
        }

        return $out;
    }

    /** Drop daily counters older than $before. */
    public function pruneDaily(string $feed, \DateTimeInterface $before, int $chunk = 5000): int
    {
        $deleted = 0;
        $cutoff = \Carbon\Carbon::instance($before)->toDateString();
        do {
            $ids = $this->connection()->table('rec_item_daily')->where('feed', $feed)->where('day', '<', $cutoff)
                ->orderBy('id')->limit($chunk)->pluck('id')->all();
            if ($ids !== []) {
                $deleted += $this->connection()->table('rec_item_daily')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $chunk);

        return $deleted;
    }

    /**
     * Raw score rows for some items plus the feed's highest `hot`.
     *
     * @param  list<int|string>  $ids
     * @return array{rows: array<int|string, array{hot: ?float, positive: int, exposure: int, impressions: int, last_event_at: ?string}>, max_hot: ?float}
     */
    public function rows(string $feed, array $ids): array
    {
        $rows = [];
        foreach (array_chunk($ids, 1000) as $part) {
            $found = $this->connection()->table('rec_item_scores')
                ->where('feed', $feed)
                ->whereIn('item_id', array_map('strval', $part))
                ->get(['item_id', 'hot', 'positive', 'exposure', 'impressions', 'last_event_at']);
            foreach ($found as $row) {
                $rows[Ids::normalize(Value::string($row->item_id))] = [
                    'hot' => $row->hot === null ? null : Value::float($row->hot),
                    'positive' => Value::int($row->positive),
                    'exposure' => Value::int($row->exposure),
                    'impressions' => Value::int($row->impressions),
                    'last_event_at' => Value::nullableString($row->last_event_at),
                ];
            }
        }

        $max = $this->connection()->table('rec_item_scores')->where('feed', $feed)->max('hot');

        return ['rows' => $rows, 'max_hot' => $max === null ? null : Value::float($max)];
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

        $max = Value::float($rows->first()->hot);
        $out = [];
        foreach ($rows as $row) {
            $out[Ids::normalize(Value::string($row->item_id))] = exp(Value::float($row->hot) - $max);
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, array{positive: int, exposure: int, impressions: int}>
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
                ->get(['item_id', 'positive', 'exposure', 'impressions']);
            foreach ($rows as $row) {
                $out[Ids::normalize(Value::string($row->item_id))] = [
                    'positive' => Value::int($row->positive),
                    'exposure' => Value::int($row->exposure),
                    'impressions' => Value::int($row->impressions),
                ];
            }
        }

        return $out;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection(Value::nullableString(config('recommender.database_connection')));
    }
}
