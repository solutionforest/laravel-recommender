<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Maintenance\ScoreRebuilder;
use SolutionForest\Recommender\Recommender;

class RebuildScoresCommand extends Command
{
    protected $signature = 'recommender:rebuild-scores
        {feed : The one feed to rebuild}
        {--days= : Backfill window in days (default: the feed\'s scores_backfill_days)}
        {--dry-run : Show what would happen without changing anything}
        {--force : Do not ask for confirmation}';

    protected $description = 'Recompute one feed\'s scores from scratch over a backfill window (safe: never double-counts)';

    public function handle(Recommender $recommender, ScoreRebuilder $rebuilder): int
    {
        $name = $this->argument('feed');
        $feed = $recommender->feed(is_string($name) ? $name : '');
        $daysOption = $this->option('days');
        $days = is_numeric($daysOption) ? max(1, (int) $daysOption) : $feed->int('scores_backfill_days', 30);
        $plan = $rebuilder->plan($feed, $days);

        $this->components->twoColumnDetail('Feed', $plan['feed']);
        $this->components->twoColumnDetail('Score rows to reset', number_format($plan['rows']));
        $this->components->twoColumnDetail('Current watermark', $plan['watermark'] ?? '—');
        $this->components->twoColumnDetail('Replay window', "{$plan['from']} → {$plan['to']} ({$days} days)");
        $this->components->twoColumnDetail('Previous rebuild', $plan['interrupted'] ? 'interrupted — this run finishes it' : '—');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Reset [{$plan['feed']}] scores and replay {$days} days?", false)) {
            $this->warn('Aborted: nothing changed. Use --force to skip this question.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $result = $rebuilder->run($feed, $days);
        $recommender->forgetTrending($feed->name);

        $this->info(sprintf(
            '[%s] reset %d rows, replayed %d events -> %d row upserts (%s .. %s) in %.2fs',
            $feed->name, $result['deleted'], $result['events'], $result['items'], $result['from'], $result['to'], microtime(true) - $started,
        ));
        $this->line("Next: <comment>php artisan recommender:build-neighbours {$feed->name}</comment> if history_days changed. Cached user lists refresh within feed_ttl.");

        return self::SUCCESS;
    }
}
