<?php

namespace SolutionForest\Recommender\Interactions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Support\Interaction;

/**
 * Reads interactions straight from your tables, so most feeds need no
 * InteractionSource class. Each table holds one event type and has a user
 * id, an item id and a timestamp column:
 *
 *     'interactions' => [
 *         'post_likes' => 'like',
 *         'post_views' => 'view',
 *         // per-table overrides (item / user / timestamp / key columns):
 *         'bookmarks' => ['event' => 'like', 'item' => 'bookmarkable_id'],
 *     ],
 *     'item_column' => 'post_id',      // default: {model}_id, e.g. post_id
 *     'user_column' => 'user_id',      // default
 *     'timestamp_column' => 'created_at', // default
 *     'history_events' => ['like'],    // neighbours; default: positive_events
 *     'interactions_connection' => null, // default DB connection
 *
 * Ids are passed through as-is (integers, UUIDs and ULIDs all work).
 * Index (timestamp), (user, timestamp) and the primary key on each table.
 */
final class DatabaseInteractions implements InteractionSource
{
    private const USER_CHUNK = 500;

    /** @var array<string, array{event: string, item: string, user: string, timestamp: string, key: string}> */
    private array $tables = [];

    public function __construct(private readonly FeedDefinition $feed)
    {
        foreach ((array) $feed->get('interactions', []) as $table => $definition) {
            $definition = is_array($definition) ? $definition : ['event' => $definition];
            if (! is_string($table) || ! isset($definition['event'])) {
                throw new InvalidArgumentException("Feed [{$feed->name}] 'interactions' must map table names to event names.");
            }

            $this->tables[$table] = [
                'event' => (string) $definition['event'],
                'item' => (string) ($definition['item'] ?? $this->itemColumn()),
                'user' => (string) ($definition['user'] ?? $feed->get('user_column', 'user_id')),
                'timestamp' => (string) ($definition['timestamp'] ?? $feed->get('timestamp_column', 'created_at')),
                'key' => (string) ($definition['key'] ?? 'id'),
            ];
        }
    }

    /**
     * Resolved table definitions (used by health checks).
     *
     * @return array<string, array{event: string, item: string, user: string, timestamp: string, key: string}>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        foreach ($this->tables as $table => $t) {
            $rows = $this->query($table)
                ->where($t['timestamp'], '>', $from)
                ->where($t['timestamp'], '<=', $to)
                ->select([$t['key'], $t['user'], $t['item'], $t['timestamp']]);

            foreach ($rows->lazyById(1000, $t['key']) as $row) {
                yield new Interaction(
                    self::id($row->{$t['user']}),
                    self::id($row->{$t['item']}),
                    $t['event'],
                    CarbonImmutable::parse($row->{$t['timestamp']}),
                );
            }
        }
    }

    public function userHistories(DateTimeInterface $since, int $perUser): iterable
    {
        $tables = $this->historyTables();
        $cursor = null;

        do {
            // Next chunk of active users, ordered by the database so string
            // ids (UUID / ULID) page as reliably as integers.
            $union = null;
            foreach ($tables as $table => $t) {
                $q = $this->query($table)->select("{$t['user']} as uid")->where($t['timestamp'], '>=', $since);
                $union = $union ? $union->union($q) : $q;
            }
            if ($union === null) {
                return;
            }
            $users = $this->connection()->query()->fromSub($union, 'u')
                ->when($cursor !== null, fn (Builder $q) => $q->where('uid', '>', $cursor))
                ->distinct()
                ->orderBy('uid')
                ->limit(self::USER_CHUNK)
                ->pluck('uid')
                ->all();

            if ($users === []) {
                return;
            }
            $cursor = end($users);

            $histories = [];
            foreach ($tables as $table => $t) {
                $rows = $this->query($table)
                    ->whereIn($t['user'], $users)
                    ->where($t['timestamp'], '>=', $since)
                    ->get([$t['user'], $t['item'], $t['timestamp']]);
                foreach ($rows as $row) {
                    $histories[(string) $row->{$t['user']}][] = [$row->{$t['item']}, (string) $row->{$t['timestamp']}];
                }
            }

            foreach ($histories as $userId => $events) {
                yield self::id($userId) => self::newestDistinct($events, $perUser);
            }
        } while (count($users) === self::USER_CHUNK);
    }

    public function recentItems(int|string $userId, int $limit): array
    {
        $events = [];
        foreach ($this->historyTables() as $table => $t) {
            $rows = $this->query($table)
                ->where($t['user'], $userId)
                ->orderByDesc($t['timestamp'])
                ->limit($limit)
                ->get([$t['item'], $t['timestamp']]);
            foreach ($rows as $row) {
                $events[] = [$row->{$t['item']}, (string) $row->{$t['timestamp']}];
            }
        }

        return self::newestDistinct($events, $limit);
    }

    /**
     * Tables used for neighbours / "your recent items": strong signals only
     * (`history_events`, default `positive_events`), falling back to every table.
     *
     * @return array<string, array{event: string, item: string, user: string, timestamp: string, key: string}>
     */
    private function historyTables(): array
    {
        $events = (array) ($this->feed->get('history_events') ?? $this->feed->get('positive_events', []));
        $tables = array_filter($this->tables, fn (array $t) => in_array($t['event'], $events, true));

        return $tables !== [] ? $tables : $this->tables;
    }

    private function itemColumn(): string
    {
        if ($column = $this->feed->get('item_column')) {
            return (string) $column;
        }
        if ($model = $this->feed->get('model')) {
            return Str::snake(class_basename((string) $model)).'_id';
        }

        throw new InvalidArgumentException("Feed [{$this->feed->name}] needs an 'item_column' (or a 'model') to read interactions.");
    }

    private function query(string $table): Builder
    {
        return $this->connection()->table($table);
    }

    private function connection(): Connection
    {
        $name = $this->feed->get('interactions_connection');

        return DB::connection(is_string($name) && $name !== '' ? $name : null);
    }

    /**
     * @param  list<array{0: mixed, 1: string}>  $events  [item id, timestamp]
     * @return list<int|string>
     */
    private static function newestDistinct(array $events, int $limit): array
    {
        usort($events, fn (array $a, array $b) => strcmp($b[1], $a[1]));

        $out = [];
        foreach ($events as [$item]) {
            $out[(string) $item] = self::id($item);
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_values($out);
    }

    private static function id(mixed $value): int|string
    {
        return is_int($value) ? $value : Ids::normalize((string) $value);
    }
}
