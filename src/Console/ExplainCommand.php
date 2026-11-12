<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Recommender;

class ExplainCommand extends Command
{
    protected $signature = 'recommender:explain {feed} {user? : User id (omit for the guest/trending ranking)} {--limit=20}';

    protected $description = 'Show why items are recommended to a user (per-source contributions)';

    public function handle(Recommender $recommender): int
    {
        $feed = $this->argument('feed');
        $user = $this->argument('user');
        $feedName = is_scalar($feed) ? (string) $feed : '';
        $userId = match (true) {
            $user === null, $user === '' => null,
            is_int($user) => $user,
            is_numeric($user) => (int) $user,
            is_scalar($user) => (string) $user,
            default => null,
        };

        $rows = $recommender->explain($feedName, $userId, (int) $this->option('limit'));

        $this->table(['#', 'item', 'score', 'final', 'cached', 'sources'], array_map(fn ($row, $i) => [
            $row['exploration'] ? '—' : $i + 1,
            $row['id'],
            $row['score'],
            $row['final'] ?? '—',
            $row['cached'] ?? '—',
            $row['exploration'] ? 'exploration' : collect($row['sources'])->map(fn ($v, $k) => "{$k}={$v}")->implode('  '),
        ], $rows, array_keys($rows)));

        $this->line('<fg=gray>#: blended score order · final: position after exploration + diversity in this fresh build · cached: position in the list served now (— = not in it)</>');

        return self::SUCCESS;
    }
}
