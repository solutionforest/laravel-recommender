<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Maintenance\ScoreUpdater;
use SolutionForest\Recommender\Recommender;

class UpdateScoresCommand extends Command
{
    protected $signature = 'recommender:update-scores {feed? : Feed name (default: all feeds)}';

    protected $description = 'Fold new interactions into rec_item_scores (incremental, run every ~10 minutes)';

    public function handle(Recommender $recommender, ScoreUpdater $updater): int
    {
        $feeds = $this->argument('feed') ? [$this->argument('feed')] : $recommender->feeds();

        foreach ($feeds as $name) {
            $started = microtime(true);
            $result = $updater->run($recommender->feed($name));
            $recommender->forgetTrending($name);
            $this->info(sprintf(
                '[%s] %d events -> %d row upserts (%s .. %s) in %.2fs',
                $name, $result['events'], $result['items'], $result['from'], $result['to'], microtime(true) - $started,
            ));
        }

        return self::SUCCESS;
    }
}
