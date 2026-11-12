<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Console\Concerns\ResolvesFeedArgument;
use SolutionForest\Recommender\Dashboard\Health;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Support\FeedDefinition;

class StatusCommand extends Command
{
    use ResolvesFeedArgument;

    protected $signature = 'recommender:status {feed? : Feed name (default: all feeds)}';

    protected $description = 'Show recommender health: watermarks, table sizes, neighbour graph, cache locks';

    public function handle(Recommender $recommender, Health $health): int
    {
        $overview = $health->overview();
        $this->line('Recommender '.($overview['enabled'] ? '<info>enabled</info>' : '<fg=yellow>DISABLED (kill switch)</>'));
        $this->line(
            'Cache store: <comment>'.$overview['cache_store'].'</comment> · locks: '.
            ($overview['locks']
                ? '<info>yes</info>'
                : '<fg=yellow>fallback (no LockProvider — ok for lab)</>')
        );
        $this->newLine();

        foreach ($this->feedNames($recommender) as $name) {
            $this->renderFeed($recommender->feed($name), $health);
        }

        return self::SUCCESS;
    }

    private function renderFeed(FeedDefinition $feed, Health $health): void
    {
        $m = $health->metrics($feed);
        $lag = $m['lag'] === null ? '—' : Health::human($m['lag'])." ({$m['lag']}s)";

        $this->info("Feed [{$feed->name}]");
        $this->table(['Metric', 'Value'], [
            ['scores watermark', $m['watermark']?->toDateTimeString() ?? '— (never ran update-scores)'],
            ['scores lag', $lag],
            ['rec_item_scores rows', number_format($m['scores'])." ({$m['hot']} with hot)"],
            ['newest last_event_at', $m['last_event'] ?? '—'],
            ['neighbours version', $m['version'] === null ? '— (never ran build-neighbours)' : (string) $m['version']],
            ['neighbours rows', number_format($m['neighbours'])." (shards remembered: {$m['shards']})"],
            ['history_days / per_user', $feed->int('history_days', 90).' / '.$feed->int('history_per_user', 30)],
            ['half_life_days', (string) $feed->float('half_life_days', 7)],
            ['feed_size / page_size', $feed->int('feed_size', 200).' / '.$feed->int('page_size', 12)],
        ]);

        if ($m['watermark'] === null) {
            $this->warn("  → Run: php artisan recommender:update-scores {$feed->name}");
        } elseif ($m['lag'] !== null && $m['lag'] > Health::MAX_SCORE_LAG) {
            $this->warn('  → Score watermark is >1h behind; check the every-10-minute schedule.');
        }

        if ($m['version'] === null || $m['neighbours'] === 0) {
            $this->warn("  → Run: php artisan recommender:build-neighbours {$feed->name}");
        }

        $this->newLine();
        $this->line("  Full checks: php artisan recommender:doctor {$feed->name}");
        $this->newLine();
    }
}
