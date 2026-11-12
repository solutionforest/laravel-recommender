<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Console\Concerns\ResolvesFeedArgument;
use SolutionForest\Recommender\Maintenance\NeighbourBuilder;
use SolutionForest\Recommender\Recommender;

class BuildNeighboursCommand extends Command
{
    use ResolvesFeedArgument;

    protected $signature = 'recommender:build-neighbours {feed? : Feed name (default: all feeds)}';

    protected $description = 'Rebuild item-to-item similarities (run nightly, off-peak)';

    public function handle(Recommender $recommender, NeighbourBuilder $builder): int
    {
        foreach ($this->feedNames($recommender) as $name) {
            $started = microtime(true);
            $result = $builder->run($recommender->feed($name));
            $this->info(sprintf(
                '[%s] %d users -> %d items with neighbours (version %d, %d shard(s)) in %.2fs, peak memory %.1f MB',
                $name, $result['users'], $result['items'], $result['version'], $result['shards'], microtime(true) - $started,
                $result['peak_mb'],
            ));
        }

        return self::SUCCESS;
    }
}
