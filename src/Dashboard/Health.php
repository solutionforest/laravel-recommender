<?php

namespace SolutionForest\Recommender\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Contracts\CardTransformer;
use SolutionForest\Recommender\Contracts\DiversityGroups;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Insights\SlowWatch;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Storage\NeighbourStore;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Storage\StateStore;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Value;
use Throwable;

/**
 * Read-only health of the recommender: per-feed metrics and configuration
 * checks. Shared by the dashboard, `recommender:status` and
 * `recommender:doctor`. Every call is a handful of indexed queries.
 */
final class Health
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Score lag above this is a warning (the schedule runs every ~10 min). */
    public const MAX_SCORE_LAG = 3600;

    public function __construct(
        private readonly Recommender $recommender,
        private readonly StateStore $state,
        private readonly NeighbourStore $neighbours,
        private readonly ScoreStore $scores,
        private readonly FeedCache $cache,
        private readonly DatabaseManager $db,
        private readonly SlowWatch $slow,
    ) {
    }

    /**
     * @return array{enabled: bool, cache_store: string, locks: bool, feeds: list<string>}
     */
    public function overview(): array
    {
        $configured = $this->cache->storeName();

        return [
            'enabled' => $this->recommender->enabled(),
            'cache_store' => is_string($configured) && $configured !== '' ? $configured : Value::string(config('cache.default', 'default')),
            'locks' => $this->cache->supportsLocks(),
            'feeds' => $this->recommender->feeds(),
        ];
    }

    /**
     * @return array{watermark: ?CarbonImmutable, lag: ?int, scores: int, hot: int, last_event: ?string, version: ?int, neighbours: int, shards: string}
     */
    public function metrics(FeedDefinition $feed, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $conn = $this->db->connection(Value::nullableString(config('recommender.database_connection')));

        $raw = $this->state->get("scores:{$feed->name}:watermark");
        $watermark = $raw !== null ? CarbonImmutable::createFromTimestamp((int) $raw, date_default_timezone_get()) : null;
        $version = $this->neighbours->activeVersion($feed->name);
        $scores = $conn->table('rec_item_scores')->where('feed', $feed->name);
        $lastEvent = (clone $scores)->max('last_event_at');

        return [
            'watermark' => $watermark,
            'lag' => $watermark !== null ? max(0, $now->getTimestamp() - $watermark->getTimestamp()) : null,
            'scores' => (int) (clone $scores)->count(),
            'hot' => (int) (clone $scores)->whereNotNull('hot')->count(),
            'last_event' => $lastEvent !== null ? Value::string($lastEvent) : null,
            'version' => $version,
            'neighbours' => $version === null ? 0 : (int) $conn->table('rec_item_neighbours')
                ->where('feed', $feed->name)->where('version', $version)->count(),
            'shards' => $this->state->get("neighbours:{$feed->name}:shards") ?? '1',
        ];
    }

    /**
     * Most popular items right now with their quality counters.
     *
     * `quality_7d` / `trend` compare the last 7 days with the last 30.
     *
     * @return list<array{id: int|string, hot: float, positive: int, exposure: int, impressions: int, rate: ?float, quality: float, quality_7d: ?float, trend: ?string}>
     */
    public function trending(FeedDefinition $feed, int $limit = 10): array
    {
        $hot = $this->scores->hottest($feed->name, $limit);
        $ids = array_keys($hot);
        $all = $this->recommender->stats($feed->name, $ids);
        $week = $this->recommender->stats($feed->name, $ids, 7);
        $month = $this->recommender->stats($feed->name, $ids, 30);

        $out = [];
        foreach ($hot as $id => $score) {
            $s = $all[$id];
            $q7 = $week[$id]['rate'] === null ? null : $week[$id]['quality'];
            $q30 = $month[$id]['rate'] === null ? null : $month[$id]['quality'];
            $out[] = [
                'id' => $id,
                'hot' => round($score, 4),
                'positive' => $s['positive'],
                'exposure' => $s['exposure'],
                'impressions' => $s['impressions'],
                'rate' => $s['rate'],
                'quality' => $s['quality'],
                'quality_7d' => $q7,
                'trend' => $q7 === null || $q30 === null ? null : ($q7 - $q30 > 0.02 ? 'up' : ($q30 - $q7 > 0.02 ? 'down' : 'flat')),
            ];
        }

        return $out;
    }

    /**
     * Configuration and pipeline checks, worst first.
     *
     * @return list<array{level: string, label: string, hint: ?string, stage: string}>
     */
    public function checks(FeedDefinition $feed, ?CarbonImmutable $now = null): array
    {
        $checks = [
            ...$this->bindingChecks($feed),
            ...$this->pipelineChecks($feed, $this->metrics($feed, $now)),
            ...$this->speedChecks($feed),
        ];
        if (! $this->recommender->enabled($feed->name)) {
            $checks[] = self::warn(
                config('recommender.enabled', true) ? "Feed [{$feed->name}] is switched off" : 'Recommender is switched off (RECOMMENDER_ENABLED=false)',
                'Serving the shared trending list only; jobs paused. Remove the switch to turn it back on.',
                'serving',
            );
        }
        $store = $this->cache->storeName() ?? Value::string(config('cache.default'));
        $driver = Value::string(config("cache.stores.{$store}.driver", $store));
        $checks[] = match ($driver) {
            'redis' => self::ok("Cache: {$store} (best for production)", 'serving'),
            'database' => ['level' => self::OK, 'label' => "Cache: {$store} — fine for small and medium apps", 'hint' => 'For heavy traffic add Redis to the app — the package switches to it automatically. Expired rows are cleaned by recommender:prune.', 'stage' => 'serving'],
            'array', 'null' => self::warn("Cache: {$store} does not persist between requests", 'Use redis (picked automatically when configured) or database.', 'serving'),
            default => self::ok("Cache: {$store}", 'serving'),
        };
        if (! $this->cache->supportsLocks()) {
            $checks[] = self::warn('Cache store has no locks', 'Concurrent builds fall back to last-write-wins. Use redis, or database + cache_locks.', 'serving');
        }

        $rank = [self::FAIL => 0, self::WARN => 1, self::OK => 2];
        usort($checks, fn (array $a, array $b) => $rank[$a['level']] <=> $rank[$b['level']]);

        return $checks;
    }

    /**
     * Worst level per pipeline stage: setup → scores → neighbours → serving.
     *
     * @param  list<array{level: string, label: string, hint: ?string, stage: string}>  $checks
     * @return array<string, string>
     */
    public static function stages(array $checks): array
    {
        $out = [];
        foreach (['setup', 'scores', 'neighbours', 'serving'] as $stage) {
            $out[$stage] = self::worst(array_values(array_filter($checks, fn (array $c) => $c['stage'] === $stage)));
        }

        return $out;
    }

    /**
     * @param  list<array{level: string, label: string, hint: ?string, stage: string}>  $checks
     */
    public static function worst(array $checks): string
    {
        $levels = array_column($checks, 'level');

        return in_array(self::FAIL, $levels, true) ? self::FAIL : (in_array(self::WARN, $levels, true) ? self::WARN : self::OK);
    }

    // ---------------------------------------------------------------------

    /**
     * @return list<array{level: string, label: string, hint: ?string, stage: string}>
     */
    private function bindingChecks(FeedDefinition $feed): array
    {
        $checks = [];
        $model = $feed->get('model');

        if ($model !== null && $model !== '') {
            if (! is_string($model) || ! is_a($model, Model::class, true)) {
                $checks[] = self::fail("model [".(is_string($model) ? $model : gettype($model))."] is not an Eloquent model");
            } else {
                $table = (new $model)->getTable();
                $checks[] = $this->tableExists($table, (new $model)->getConnectionName())
                    ? self::ok('model '.class_basename($model)." → table {$table}")
                    : self::fail("model table [{$table}] does not exist", 'Run your migrations.');
            }
        }

        $interactions = $feed->get('interactions');
        if (is_array($interactions) && $interactions !== []) {
            $checks = [...$checks, ...$this->tableChecks($feed, $interactions)];
        } elseif (is_string($interactions) && $interactions !== '') {
            $checks[] = $this->classCheck('interactions', $interactions, InteractionSource::class);
        } else {
            $checks[] = self::fail('No interactions configured', "Set 'interactions' => ['likes_table' => 'like', ...] or an InteractionSource class.");
        }

        foreach (['filter' => ItemFilter::class, 'cards' => CardTransformer::class, 'groups' => DiversityGroups::class] as $key => $contract) {
            if (is_string($class = $feed->get($key)) && $class !== '') {
                $checks[] = $this->classCheck($key, $class, $contract);
            }
        }
        if (! $feed->get('cards') && ! $feed->get('model')) {
            $checks[] = self::fail('No cards', "Set 'model' (cards from toRecommendationCard()) or a 'cards' class.");
        }

        foreach ([...$feed->weighted('sources'), ...$feed->weighted('trending_sources')] as $class => $weight) {
            $checks[] = $this->classCheck('source', $class, CandidateSource::class);
        }
        foreach ($feed->weighted('boosters') as $class => $weight) {
            $checks[] = $this->classCheck('booster', $class, Booster::class);
        }
        if ($feed->weighted('sources') === []) {
            $checks[] = self::warn('No sources', 'Users only ever see trending items.');
        }

        // De-duplicate (a class listed in sources and trending_sources).
        return array_values(array_unique($checks, SORT_REGULAR));
    }

    /**
     * @param  array<mixed>  $interactions
     * @return list<array{level: string, label: string, hint: ?string, stage: string}>
     */
    private function tableChecks(FeedDefinition $feed, array $interactions): array
    {
        $checks = [];
        $weights = (array) $feed->get('events', []);
        $connection = is_string($c = $feed->get('interactions_connection')) && $c !== '' ? $c : null;

        try {
            $source = new \SolutionForest\Recommender\Interactions\DatabaseInteractions($feed);
        } catch (Throwable $e) {
            return [self::fail('interactions: '.$e->getMessage())];
        }

        foreach ($source->tables() as $table => $t) {
            if (! $this->tableExists($table, $connection)) {
                $checks[] = self::fail("interactions table [{$table}] does not exist");

                continue;
            }
            $schema = $this->db->connection($connection)->getSchemaBuilder();
            $missing = array_values(array_filter(
                array_unique([$t['key'], $t['user'], $t['item'], $t['timestamp']]),
                fn (string $column) => ! $schema->hasColumn($table, $column),
            ));
            $checks[] = $missing === []
                ? self::ok("{$table} → '{$t['event']}'")
                : self::fail("{$table} is missing column(s): ".implode(', ', $missing), "Set item_column / user_column / timestamp_column, or per table: '{$table}' => ['event' => '{$t['event']}', 'item' => '...'].");

            if (Value::float($weights[$t['event']] ?? 0) <= 0) {
                $checks[] = self::warn("Event '{$t['event']}' has no weight", "Add it to 'events' (e.g. '{$t['event']}' => 2.0) or its rows are ignored by update-scores.");
            }
        }

        return $checks;
    }

    /**
     * @param  array{watermark: ?CarbonImmutable, lag: ?int, scores: int, hot: int, last_event: ?string, version: ?int, neighbours: int, shards: string}  $m
     * @return list<array{level: string, label: string, hint: ?string, stage: string}>
     */
    private function pipelineChecks(FeedDefinition $feed, array $m): array
    {
        $checks = [];

        if ($this->state->get(\SolutionForest\Recommender\Maintenance\ScoreRebuilder::markerKey($feed->name)) !== null) {
            $checks[] = self::fail('Score rebuild was interrupted', "php artisan recommender:rebuild-scores {$feed->name}", 'scores');
        }

        if ($m['watermark'] === null) {
            $checks[] = self::fail('Scores never ran', "php artisan recommender:update-scores {$feed->name}", 'scores');
        } elseif ($m['lag'] > self::MAX_SCORE_LAG) {
            $checks[] = self::warn('Scores are '.self::human((int) $m['lag']).' behind', 'Check the scheduler runs recommender:update-scores every ~10 minutes.', 'scores');
        } else {
            $checks[] = self::ok('Scores up to date ('.self::human((int) $m['lag']).' lag)', 'scores');
        }

        if ($m['watermark'] !== null && $m['scores'] === 0) {
            $checks[] = self::warn('No scored items yet', 'No interactions were found in the backfill window (scores_backfill_days).', 'scores');
        }

        if ($m['version'] === null) {
            $checks[] = self::fail('Neighbours never built', "php artisan recommender:build-neighbours {$feed->name}", 'neighbours');
        } elseif ($m['neighbours'] === 0) {
            $checks[] = self::warn('Neighbour graph is empty', 'Too little shared history; widen history_days or lower neighbours_min_co_count.', 'neighbours');
        } else {
            $checks[] = self::ok(number_format($m['neighbours']).' items have neighbours', 'neighbours');
        }

        return $checks;
    }

    /**
     * Slow app code / SQL seen recently, and how long the last job runs took.
     *
     * @return list<array{level: string, label: string, hint: ?string, stage: string}>
     */
    private function speedChecks(FeedDefinition $feed): array
    {
        $checks = [];
        $hints = [
            'source' => 'A source should be one or two indexed queries with a LIMIT. See docs/operations.md#your-sql-matters.',
            'booster' => 'A booster runs on every build: one indexed whereIn over the candidate ids, no per-item queries.',
            'filter' => 'Runs on every build for all candidates: keep the scope to indexed columns; avoid whereHas on large relations (join or a denormalised column instead).',
            'cards' => 'Eager-load what the card needs (cards are rendered in one query per page), or cache heavy fields on the model.',
            'groups' => 'Groups read one attribute per item; use a column on the model, not a relationship.',
            'tail' => 'The catalog tail runs the recommendable scope ordered by tail_order: index that column together with the scope\'s columns.',
            'query' => 'Slow SQL while the job ran: usually an interaction table without an index on (timestamp) and (user, timestamp), or a heavy query in a custom InteractionSource. Slow jobs run longer, overlap and wait on locks.',
        ];
        foreach ($this->slow->entries($feed) as $e) {
            $what = $e['kind'] === 'query' ? "SQL during {$e['stage']}: {$e['name']}" : "{$e['kind']} {$e['name']}";
            $checks[] = self::warn(
                sprintf('Slow %s — up to %s ms (%d× in 7 days, last %s ago)', $what, number_format($e['max_ms']), $e['count'], self::human(max(0, time() - $e['last_at']))),
                $hints[$e['kind']] ?? null,
                $e['stage'] === 'serving' ? 'serving' : $e['stage'],
            );
        }

        foreach (['update-scores' => ['scores', 'slow_scores_seconds', 300], 'build-neighbours' => ['neighbours', 'slow_neighbours_seconds', 1800]] as $job => [$stage, $key, $default]) {
            $run = $this->slow->lastRun($feed, $job);
            if ($run === null) {
                continue;
            }
            $took = self::human((int) round($run['seconds']));
            $checks[] = $run['seconds'] > $feed->int($key, $default)
                ? self::warn("Last {$job} run took {$took}", "Over {$key} ({$feed->int($key, $default)} s). Look for slow SQL above; long runs overlap with the next one and wait on locks.", $stage)
                : self::ok(sprintf('Last %s run took %s', $job, $run['seconds'] < 1 ? number_format($run['seconds'] * 1000).' ms' : $took), $stage);
        }

        return $checks;
    }

    /**
     * @return array{level: string, label: string, hint: ?string, stage: string}
     */
    private function classCheck(string $role, string $class, string $contract): array
    {
        if (! class_exists($class)) {
            return self::fail("{$role} class [{$class}] not found");
        }
        if (! is_subclass_of($class, $contract)) {
            return self::fail("{$role} [{$class}] does not implement ".class_basename($contract));
        }

        return self::ok("{$role} ".class_basename($class));
    }

    private function tableExists(string $table, ?string $connection): bool
    {
        try {
            return $this->db->connection($connection)->getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    public static function human(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h',
            default => intdiv($seconds, 86400).'d',
        };
    }

    /** @return array{level: string, label: string, hint: ?string, stage: string} */
    private static function ok(string $label, string $stage = 'setup'): array
    {
        return ['level' => self::OK, 'label' => $label, 'hint' => null, 'stage' => $stage];
    }

    /** @return array{level: string, label: string, hint: ?string, stage: string} */
    private static function warn(string $label, ?string $hint = null, string $stage = 'setup'): array
    {
        return ['level' => self::WARN, 'label' => $label, 'hint' => $hint, 'stage' => $stage];
    }

    /** @return array{level: string, label: string, hint: ?string, stage: string} */
    private static function fail(string $label, ?string $hint = null, string $stage = 'setup'): array
    {
        return ['level' => self::FAIL, 'label' => $label, 'hint' => $hint, 'stage' => $stage];
    }
}
