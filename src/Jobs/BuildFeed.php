<?php

namespace SolutionForest\Recommender\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Support\Value;

/**
 * Pre-builds a user's list in the background (e.g. on login / app open) so
 * the first request is a cache hit. Unique per feed+user: a burst of logins
 * for the same user queues only one build. Pass `attributes` (the ones in
 * `list_vary_by`) to warm a tenant- or device-specific list.
 */
class BuildFeed implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $feed,
        public readonly int|string $userId,
        public readonly array $attributes = [],
    ) {
        $this->onQueue(Value::nullableString(config('recommender.queue', 'recommender')));
        if ($connection = Value::nullableString(config('recommender.queue_connection'))) {
            $this->onConnection($connection);
        }
    }

    public function uniqueId(): string
    {
        // One build per user and `list_vary_by` variant (e.g. per tenant).
        $variant = app(Recommender::class)->listVariant($this->feed, $this->attributes);

        return "{$this->feed}:{$this->userId}".($variant === null ? '' : ":{$variant}");
    }

    public function handle(Recommender $recommender): void
    {
        $recommender->build($this->feed, $this->userId, null, $this->attributes);
    }
}
