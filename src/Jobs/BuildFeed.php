<?php

namespace SolutionForest\Recommender\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SolutionForest\Recommender\Recommender;

/**
 * Pre-builds a user's list in the background (e.g. on login / app open) so
 * the first request is a cache hit. Unique per feed+user: a burst of logins
 * for the same user queues only one build.
 */
class BuildFeed implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $feed,
        public readonly int|string $userId,
    ) {
        $this->onQueue(config('recommender.queue', 'recommender'));
        if ($connection = config('recommender.queue_connection')) {
            $this->onConnection($connection);
        }
    }

    public function uniqueId(): string
    {
        return "{$this->feed}:{$this->userId}";
    }

    public function handle(Recommender $recommender): void
    {
        $recommender->build($this->feed, $this->userId);
    }
}
