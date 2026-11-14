<?php

namespace SolutionForest\Recommender\Insights;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Storage\StateStore;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Value;

/**
 * Notices slow parts the package does not control, so `doctor` and the
 * dashboard can point at them before they hurt:
 *
 *  - app code called while serving: sources, boosters, the `recommendable`
 *    scope or `filter`, cards, groups and the catalog tail, timed per call
 *    (`slow_ms`, default 200 ms);
 *  - every SQL query run while `update-scores` or `build-neighbours` works
 *    (`slow_query_ms`, default 1000 ms) — e.g. an interaction table without
 *    an index, or a custom InteractionSource with a heavy relationship query.
 *    Slow reads keep the jobs running longer, which makes overlapping runs
 *    and lock waits (deadlocks) more likely;
 *  - how long each job run took (`slow_scores_seconds` 300,
 *    `slow_neighbours_seconds` 1800).
 *
 * Cost: one `hrtime()` pair per call. Only slow events are written (to the
 * cache, for 7 days, at most 20 per feed), and each logs a warning at most
 * once an hour.
 */
final class SlowWatch
{
    private const KEEP_SECONDS = 7 * 86400;

    private const MAX_ENTRIES = 20;

    /** The job whose queries are being watched, if any. */
    private ?FeedDefinition $jobFeed = null;

    private string $job = '';

    public function __construct(
        private readonly FeedCache $cache,
        private readonly StateStore $state,
    ) {
    }

    /**
     * Run $call and record it when it is slower than `slow_ms`.
     *
     * @template T
     *
     * @param  string  $kind  source | booster | filter | cards | groups | tail
     * @param  callable(): T  $call
     * @return T
     */
    public function measure(FeedDefinition $feed, string $kind, string $name, callable $call): mixed
    {
        $start = hrtime(true);
        try {
            return $call();
        } finally {
            $ms = (hrtime(true) - $start) / 1e6;
            if ($ms >= $feed->int('slow_ms', 200)) {
                $this->record($feed, 'serving', $kind, $name, $ms);
            }
        }
    }

    /**
     * Run a job ($job = update-scores | build-neighbours), watching its SQL
     * queries and storing how long it took.
     *
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    public function job(FeedDefinition $feed, string $job, callable $run): mixed
    {
        [$outerFeed, $outerJob] = [$this->jobFeed, $this->job];
        [$this->jobFeed, $this->job] = [$feed, $job];
        $start = hrtime(true);
        try {
            $result = $run();
            $this->state->put(self::runKey($feed, $job), sprintf('%.3f|%d', (hrtime(true) - $start) / 1e9, time()));

            return $result;
        } finally {
            [$this->jobFeed, $this->job] = [$outerFeed, $outerJob];
        }
    }

    /** Database listener (registered by the service provider). */
    public function query(QueryExecuted $event): void
    {
        $feed = $this->jobFeed;
        if ($feed === null || $event->time < $feed->int('slow_query_ms', 1000)) {
            return;
        }
        $sql = (string) preg_replace('/\s+/', ' ', trim($event->sql));
        if (str_contains($sql, 'rec_state')) {
            return; // our own bookkeeping, never the cause
        }
        $this->record($feed, $this->job === 'build-neighbours' ? 'neighbours' : 'scores', 'query', mb_substr($sql, 0, 300), (float) $event->time);
    }

    /**
     * Slow events of the last 7 days, slowest first.
     *
     * @return list<array{stage: string, kind: string, name: string, count: int, max_ms: float, last_ms: float, last_at: int}>
     */
    public function entries(FeedDefinition $feed): array
    {
        $entries = array_values($this->stored($feed));
        usort($entries, fn (array $a, array $b) => $b['max_ms'] <=> $a['max_ms']);

        return $entries;
    }

    /**
     * Duration of the last run of a job, if it ever ran.
     *
     * @return array{seconds: float, at: int}|null
     */
    public function lastRun(FeedDefinition $feed, string $job): ?array
    {
        $value = $this->state->get(self::runKey($feed, $job));
        if ($value === null || ! str_contains($value, '|')) {
            return null;
        }
        [$seconds, $at] = explode('|', $value, 2);

        return ['seconds' => Value::float($seconds), 'at' => Value::int($at)];
    }

    public function forget(FeedDefinition $feed): void
    {
        $this->cache->putDiagnostics($feed, 'slow', [], self::KEEP_SECONDS);
    }

    private function record(FeedDefinition $feed, string $stage, string $kind, string $name, float $ms): void
    {
        try {
            $entries = $this->stored($feed);
            $id = md5("{$stage}|{$kind}|{$name}");
            $old = $entries[$id] ?? null;
            $entries[$id] = [
                'stage' => $stage,
                'kind' => $kind,
                'name' => $name,
                'count' => ($old['count'] ?? 0) + 1,
                'max_ms' => round(max($old['max_ms'] ?? 0.0, $ms), 1),
                'last_ms' => round($ms, 1),
                'last_at' => time(),
            ];
            if (count($entries) > self::MAX_ENTRIES) {
                uasort($entries, fn (array $a, array $b) => $b['last_at'] <=> $a['last_at']);
                $entries = array_slice($entries, 0, self::MAX_ENTRIES, true);
            }
            $this->cache->putDiagnostics($feed, 'slow', $entries, self::KEEP_SECONDS);

            if ($this->cache->once($feed, "slow-log:{$id}", 3600)) {
                Log::warning(sprintf('[recommender:%s] slow %s (%s): %s took %d ms', $feed->name, $kind, $stage, $name, (int) $ms));
            }
        } catch (\Throwable) {
            // Diagnostics must never break a page or a job.
        }
    }

    /**
     * @return array<string, array{stage: string, kind: string, name: string, count: int, max_ms: float, last_ms: float, last_at: int}>
     */
    private function stored(FeedDefinition $feed): array
    {
        $out = [];
        $cutoff = time() - self::KEEP_SECONDS;
        foreach ($this->cache->diagnostics($feed, 'slow') as $id => $entry) {
            if (! is_array($entry) || Value::int($entry['last_at'] ?? 0) < $cutoff) {
                continue;
            }
            $out[(string) $id] = [
                'stage' => Value::string($entry['stage'] ?? ''),
                'kind' => Value::string($entry['kind'] ?? ''),
                'name' => Value::string($entry['name'] ?? ''),
                'count' => Value::int($entry['count'] ?? 0),
                'max_ms' => Value::float($entry['max_ms'] ?? 0),
                'last_ms' => Value::float($entry['last_ms'] ?? 0),
                'last_at' => Value::int($entry['last_at'] ?? 0),
            ];
        }

        return $out;
    }

    /** A readable name for an app-facing part, e.g. "recommendable scope (Post)". */
    public static function name(FeedDefinition $feed, object $part): string
    {
        $model = class_basename(Value::string($feed->get('model')));

        return match (true) {
            $part instanceof \SolutionForest\Recommender\Eloquent\ModelFilter => "recommendable scope ({$model})",
            $part instanceof \SolutionForest\Recommender\Eloquent\ModelCards => "toRecommendationCard ({$model})",
            $part instanceof \SolutionForest\Recommender\Eloquent\ModelGroups => "diversity groups ({$model})",
            $part instanceof \SolutionForest\Recommender\Eloquent\CatalogTail => "catalog tail ({$model})",
            default => class_basename($part),
        };
    }

    private static function runKey(FeedDefinition $feed, string $job): string
    {
        return "run:{$feed->name}:{$job}";
    }
}
