<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Console\Concerns\ResolvesFeedArgument;
use SolutionForest\Recommender\Dashboard\Health;
use SolutionForest\Recommender\Recommender;

class DoctorCommand extends Command
{
    use ResolvesFeedArgument;

    protected $signature = 'recommender:doctor {feed? : Feed name (default: all feeds)}';

    protected $description = 'Check feed configuration, tables, columns and jobs; exits non-zero on failures';

    public function handle(Recommender $recommender, Health $health): int
    {
        $failed = false;
        $icons = [Health::OK => '<info>✓</info>', Health::WARN => '<fg=yellow>!</>', Health::FAIL => '<fg=red>✗</>'];

        foreach ($this->feedNames($recommender) as $name) {
            $checks = $health->checks($recommender->feed($name));
            $this->line("<options=bold>Feed [{$name}]</> — ".strtoupper(Health::worst($checks)));
            foreach ($checks as $check) {
                $this->line("  {$icons[$check['level']]} {$check['label']}");
                if ($check['hint'] !== null) {
                    $this->line("      <fg=gray>{$check['hint']}</>");
                }
            }
            $this->newLine();
            $failed = $failed || Health::worst($checks) === Health::FAIL;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
