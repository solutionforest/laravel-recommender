<?php

namespace SolutionForest\Recommender\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @internal One place for "this part failed, keep serving" log lines.
 */
final class Report
{
    public static function failure(FeedDefinition $feed, string $stage, Throwable $e): void
    {
        Log::warning("[recommender:{$feed->name}] {$stage} failed: {$e->getMessage()}", ['exception' => $e]);
    }
}
