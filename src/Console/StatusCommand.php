<?php

namespace SolutionForest\Recommender\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use SolutionForest\Recommender\Console\Concerns\ResolvesFeedArgument;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Storage\NeighbourStore;
use SolutionForest\Recommender\Storage\StateStore;
use SolutionForest\Recommender\Support\FeedDefinition;

class StatusCommand extends Command
{
    use ResolvesFeedArgument;

    protected $signature = 'recommender:status {feed? : Feed name (default: all feeds)}';

    protected $description = 'Show recommender health: watermarks, table sizes, neighbour graph, cache locks';

    public function handle(
        Recommender $recommender,
        StateStore $state,
        NeighbourStore $neighbours,
        FeedCache $cache,
        DatabaseManager $db,
    ): int {
        $this->line('Recommender '.($recommender->enabled() ? '<info>enabled</info>' : '<fg=yellow>DISABLED (kill switch)</>'));
        $this->line(
            'Cache store: <comment>'.$this->cacheStoreName().'</comment> · locks: '.
            ($cache->supportsLocks()
                ? '<info>yes</info>'
                : '<fg=yellow>fallback (no LockProvider — ok for lab)</>')
        );
        $this->newLine();

        foreach ($this->feedNames($recommender) as $name) {
            $this->renderFeed($recommender->feed($name), $state, $neighbours, $db);
        }

        return self::SUCCESS;
    }

    private function renderFeed(
        FeedDefinition $feed,
        StateStore $state,
        NeighbourStore $neighbours,
        DatabaseManager $db,
    ): void {
        $conn = $db->connection(config('recommender.database_connection'));
        $now = CarbonImmutable::now();

        $watermarkRaw = $state->get("scores:{$feed->name}:watermark");
        $watermark = $watermarkRaw !== null
            ? CarbonImmutable::createFromTimestamp((int) $watermarkRaw)
            : null;
        $lagSeconds = $watermark !== null ? max(0, $now->getTimestamp() - $watermark->getTimestamp()) : null;
        $lag = $lagSeconds === null ? null : $this->humanSeconds($lagSeconds);

        $scoreCount = (int) $conn->table('rec_item_scores')->where('feed', $feed->name)->count();
        $hotCount = (int) $conn->table('rec_item_scores')->where('feed', $feed->name)->whereNotNull('hot')->count();
        $lastEvent = $conn->table('rec_item_scores')->where('feed', $feed->name)->max('last_event_at');

        $version = $neighbours->activeVersion($feed->name);
        $neighbourCount = $version === null
            ? 0
            : (int) $conn->table('rec_item_neighbours')
                ->where('feed', $feed->name)
                ->where('version', $version)
                ->count();
        $shards = $state->get("neighbours:{$feed->name}:shards") ?? '1';

        $this->info("Feed [{$feed->name}]");
        $this->table(['Metric', 'Value'], [
            ['scores watermark', $watermark?->toDateTimeString() ?? '— (never ran update-scores)'],
            ['scores lag', $lagSeconds === null ? '—' : "{$lag} ({$lagSeconds}s)"],
            ['rec_item_scores rows', number_format($scoreCount)." ({$hotCount} with hot)"],
            ['newest last_event_at', $lastEvent ?? '—'],
            ['neighbours version', $version === null ? '— (never ran build-neighbours)' : (string) $version],
            ['neighbours rows', number_format($neighbourCount)." (shards remembered: {$shards})"],
            ['history_days / per_user', $feed->int('history_days', 90).' / '.$feed->int('history_per_user', 30)],
            ['half_life_days', (string) $feed->float('half_life_days', 7)],
            ['feed_size / page_size', $feed->int('feed_size', 200).' / '.$feed->int('page_size', 12)],
        ]);

        if ($watermark === null) {
            $this->warn("  → Run: php artisan recommender:update-scores {$feed->name}");
        } elseif ($lagSeconds !== null && $lagSeconds > 3600) {
            $this->warn('  → Score watermark is >1h behind; check the every-10-minute schedule.');
        }

        if ($version === null || $neighbourCount === 0) {
            $this->warn("  → Run: php artisan recommender:build-neighbours {$feed->name}");
        }

        $this->newLine();
    }

    private function cacheStoreName(): string
    {
        $configured = config('recommender.cache_store');

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('cache.default', 'default');
    }

    private function humanSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600).'h';
        }

        return intdiv($seconds, 86400).'d';
    }
}
