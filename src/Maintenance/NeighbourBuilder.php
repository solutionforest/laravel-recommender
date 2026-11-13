<?php

namespace SolutionForest\Recommender\Maintenance;

use Carbon\CarbonImmutable;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Storage\NeighbourStore;
use SolutionForest\Recommender\Storage\StateStore;
use SolutionForest\Recommender\Support\FeedDefinition;

/**
 * Item-to-item co-interaction similarity (cosine on binary user vectors):
 *
 *   sim(a, b) = users(a ∩ b) / sqrt(users(a) · users(b))
 *
 * Bounded work:
 *  - each user contributes at most history_per_user items from the last
 *    history_days days, i.e. at most k·(k-1)/2 pairs;
 *  - each item keeps only its top neighbours_max neighbours;
 *  - pair counts are built in S shards (an item belongs to shard
 *    crc32(id) % S). Only one shard's pairs are in memory at a time, so peak
 *    memory is about total / S. If a pass exceeds neighbours_memory_limit_mb
 *    the build is discarded and restarted with 2·S shards; the shard count
 *    that worked is remembered for the next run.
 *
 * Rows are bulk inserted per chunk under a new version, which is activated
 * only when every shard is written.
 */
final class NeighbourBuilder
{
    public function __construct(
        private readonly Bindings $bindings,
        private readonly NeighbourStore $store,
        private readonly StateStore $state,
        private readonly FeedCache $cache,
    ) {
    }

    /**
     * @return array{users: int, items: int, version: int, shards: int, peak_mb: float}
     */
    public function run(FeedDefinition $feed, ?CarbonImmutable $now = null): array
    {
        $source = $this->bindings->interactions($feed)
            ?? throw new \RuntimeException("Feed [{$feed->name}] has no 'interactions' configured.");

        $now ??= CarbonImmutable::now();
        $shardKey = "neighbours:{$feed->name}:shards";
        $shards = max(1, (int) ($this->state->get($shardKey) ?? 1));
        $maxShards = max(1, $feed->int('neighbours_max_shards', 64));

        while (true) {
            $version = $this->store->nextVersion($feed->name);

            try {
                $result = $this->build($feed, $source, $now, $version, $shards);
            } catch (MemoryBudgetExceeded $e) {
                $this->store->deleteVersion($feed->name, $version, $feed->int('delete_chunk', 5000));
                if ($shards >= $maxShards) {
                    throw $e;
                }
                $shards = min($maxShards, $shards * 2);

                continue;
            }

            // similar() switches to the new version before the old rows are deleted.
            $this->store->activate($feed->name, $version, $feed->int('delete_chunk', 5000), fn () => $this->cache->forgetNeighbourVersion($feed));
            $this->state->put($shardKey, (string) $shards);

            return $result + ['version' => $version, 'shards' => $shards, 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)];
        }
    }

    /**
     * @return array{users: int, items: int}
     */
    private function build(FeedDefinition $feed, InteractionSource $source, CarbonImmutable $now, int $version, int $shards): array
    {
        $since = $now->subDays($feed->int('history_days', 90));
        $perUser = max(2, $feed->int('history_per_user', 30));
        $minCo = max(1, $feed->int('neighbours_min_co_count', 2));
        $keep = max(1, $feed->int('neighbours_max', 30));
        $chunk = max(1, $feed->int('write_chunk', 500));
        $limit = self::budgetBytes($feed->int('neighbours_memory_limit_mb', 512));

        $users = 0;
        $items = 0;
        /** @var array<string, int>|null $itemUsers  shared by all shards, computed in the first pass */
        $itemUsers = null;

        for ($shard = 0; $shard < $shards; $shard++) {
            $firstPass = $itemUsers === null;
            $counts = $firstPass ? [] : $itemUsers;
            /** @var array<string, array<string, int>> $co */
            $co = [];
            $seenUsers = 0;

            foreach ($source->userHistories($since, $perUser) as $history) {
                $history = array_slice(array_values(array_unique(array_map('strval', $history))), 0, $perUser);
                $n = count($history);
                if ($n === 0) {
                    continue;
                }
                $seenUsers++;
                if ($firstPass) {
                    foreach ($history as $item) {
                        $counts[$item] = ($counts[$item] ?? 0) + 1;
                    }
                }
                $inShard = [];
                foreach ($history as $item) {
                    $inShard[$item] = $shards === 1 || self::shardOf($item, $shards) === $shard;
                }
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $a = $history[$i];
                        $b = $history[$j];
                        if ($inShard[$a]) {
                            $co[$a][$b] = ($co[$a][$b] ?? 0) + 1;
                        }
                        if ($inShard[$b]) {
                            $co[$b][$a] = ($co[$b][$a] ?? 0) + 1;
                        }
                    }
                }
                if ($seenUsers % 1000 === 0 && memory_get_usage() > $limit) {
                    throw new MemoryBudgetExceeded("Neighbour build for [{$feed->name}] exceeded {$feed->int('neighbours_memory_limit_mb', 512)} MB with {$shards} shard(s).");
                }
            }
            if (memory_get_usage() > $limit) {
                throw new MemoryBudgetExceeded("Neighbour build for [{$feed->name}] exceeded {$feed->int('neighbours_memory_limit_mb', 512)} MB with {$shards} shard(s).");
            }

            if ($firstPass) {
                $itemUsers = $counts;
                $users = $seenUsers;
            }

            // Top-N per item, bulk inserted in chunks.
            $rows = [];
            foreach ($co as $a => $pairs) {
                $sims = [];
                foreach ($pairs as $b => $count) {
                    if ($count >= $minCo) {
                        $sims[$b] = $count / sqrt($itemUsers[$a] * $itemUsers[$b]);
                    }
                }
                if ($sims === []) {
                    continue;
                }
                arsort($sims);
                $top = [];
                foreach (array_slice($sims, 0, $keep, true) as $b => $sim) {
                    $top[] = [(string) $b, round($sim, 4)];
                }
                $rows[$a] = $top;
                if (count($rows) >= $chunk) {
                    $this->store->write($feed->name, $version, $rows, $chunk);
                    $items += count($rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                $this->store->write($feed->name, $version, $rows, $chunk);
                $items += count($rows);
            }
            unset($co, $rows);
        }

        return ['users' => $users, 'items' => $items];
    }

    /**
     * Configured budget, but never more than 80% of PHP's memory_limit, so
     * the guard trips before PHP aborts with a fatal error.
     */
    public static function budgetBytes(int $configuredMb, ?string $phpLimit = null): int
    {
        $budget = $configuredMb * 1048576;
        $phpLimit ??= (string) ini_get('memory_limit');
        if ($phpLimit === '' || $phpLimit === '-1') {
            return $budget;
        }
        $unit = strtolower(substr($phpLimit, -1));
        $value = (int) $phpLimit;
        $bytes = match ($unit) {
            'g' => $value * 1073741824,
            'm' => $value * 1048576,
            'k' => $value * 1024,
            default => $value,
        };

        return $bytes > 0 ? min($budget, (int) ($bytes * 0.8)) : $budget;
    }

    public static function shardOf(string $item, int $shards): int
    {
        return crc32($item) % $shards;
    }
}
