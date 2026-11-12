<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Console\Concerns\ResolvesFeedArgument;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Storage\ScoreStore;

class PruneCommand extends Command
{
    use ResolvesFeedArgument;

    protected $signature = 'recommender:prune {feed? : Feed name (default: all feeds)}';

    protected $description = 'Delete score rows of items with no interaction for scores_retention_days (chunked, run daily)';

    public function handle(Recommender $recommender, ScoreStore $scores): int
    {
        foreach ($this->feedNames($recommender) as $name) {
            $feed = $recommender->feed($name);
            $before = now()->subDays($feed->int('scores_retention_days', 180));
            $deleted = $scores->prune($name, $before, $feed->int('delete_chunk', 5000));
            $days = $scores->pruneDaily($name, $before, $feed->int('delete_chunk', 5000));
            $this->info("[{$name}] pruned {$deleted} idle score rows and {$days} daily rows (nothing since {$before->toDateString()})");
        }

        return self::SUCCESS;
    }
}
