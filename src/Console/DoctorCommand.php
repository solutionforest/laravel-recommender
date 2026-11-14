<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Console\Concerns\ResolvesFeedArgument;
use SolutionForest\Recommender\Dashboard\Health;
use SolutionForest\Recommender\Insights\SpeedProbe;
use SolutionForest\Recommender\Recommender;

class DoctorCommand extends Command
{
    use ResolvesFeedArgument;

    protected $signature = 'recommender:doctor {feed? : Feed name (default: all feeds)} {--probe : Also run each part once on real data and time it (reads only)}';

    protected $description = 'Check feed configuration, tables, columns and jobs; exits non-zero on failures';

    public function handle(Recommender $recommender, Health $health, SpeedProbe $probe): int
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
            // Probe even before the first job run (that is when it helps most); only a broken setup stops it.
            if ($this->option('probe') && Health::stages($checks)['setup'] !== Health::FAIL) {
                $this->line('  <options=bold>Speed probe</> (each part once, on real data):');
                foreach ($probe->run($recommender->feed($name)) as $row) {
                    $time = number_format($row['ms'], $row['ms'] < 10 ? 1 : 0).' ms';
                    $this->line(sprintf(
                        '  %s %s — %s%s',
                        $row['slow'] ? $icons[Health::WARN] : $icons[Health::OK],
                        $row['part'],
                        $row['slow'] ? "<fg=yellow>{$time}</> (limit ".number_format($row['limit']).' ms)' : $time,
                        $row['detail'] !== null ? " · {$row['detail']}" : '',
                    ));
                }
            }
            $this->newLine();
            $failed = $failed || Health::worst($checks) === Health::FAIL;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
