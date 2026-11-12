<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Storage\ScoreStore;

class PruneCommand extends Command
{
    protected $signature = 'recommender:prune {feed? : Feed name (default: all feeds)}';

    protected $description = 'Delete score rows of items with no interaction for scores_retention_days (chunked, run daily)';

    public function handle(Recommender $recommender, ScoreStore $scores): int
    {
        $feeds = $this->argument('feed') ? [$this->argument('feed')] : $recommender->feeds();

        foreach ($feeds as $name) {
            $feed = $recommender->feed($name);
            $before = now()->subDays($feed->int('scores_retention_days', 180));
            $deleted = $scores->prune($name, $before, $feed->int('delete_chunk', 5000));
            $this->info("[{$name}] pruned {$deleted} idle score rows (no event since {$before->toDateString()})");
        }

        return self::SUCCESS;
    }
}
