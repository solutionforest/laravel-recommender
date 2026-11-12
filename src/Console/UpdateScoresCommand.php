<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Console\Concerns\ResolvesFeedArgument;
use SolutionForest\Recommender\Maintenance\ScoreUpdater;
use SolutionForest\Recommender\Recommender;

class UpdateScoresCommand extends Command
{
    use ResolvesFeedArgument;

    protected $signature = 'recommender:update-scores {feed? : Feed name (default: all feeds)}';

    protected $description = 'Fold new interactions into rec_item_scores (incremental, run every ~10 minutes)';

    public function handle(Recommender $recommender, ScoreUpdater $updater): int
    {
        $failed = false;
        foreach ($this->feedNames($recommender) as $name) {
            $started = microtime(true);
            try {
                $result = $updater->run($recommender->feed($name));
            } catch (\RuntimeException $e) {
                // One feed (e.g. an unfinished rebuild) must not stop the others.
                $this->error("[{$name}] {$e->getMessage()}");
                $failed = true;

                continue;
            }
            $recommender->forgetTrending($name);
            $this->info(sprintf(
                '[%s] %d events -> %d row upserts (%s .. %s) in %.2fs',
                $name, $result['events'], $result['items'], $result['from'], $result['to'], microtime(true) - $started,
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
