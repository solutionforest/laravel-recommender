<?php

namespace SolutionForest\Recommender\Maintenance;

use Carbon\CarbonImmutable;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Storage\StateStore;
use SolutionForest\Recommender\Support\FeedDefinition;

/**
 * Recompute one feed's scores from scratch over a backfill window, e.g.
 * after importing old interactions or changing event weights / half-life.
 *
 * Never double-counts: a marker is written first, then the feed's rows and
 * watermark are removed and the window is replayed. While the marker exists
 * the regular update-scores refuses to touch the feed, so an interrupted
 * rebuild can only be finished by running the rebuild again (which starts
 * over cleanly). Other feeds are never touched. Impressions are kept
 * (they are not in the app's tables, so they cannot be replayed).
 */
final class ScoreRebuilder
{
    public function __construct(
        private readonly ScoreStore $scores,
        private readonly StateStore $state,
        private readonly ScoreUpdater $updater,
    ) {
    }

    public static function markerKey(string $feed): string
    {
        return "scores:{$feed}:rebuild";
    }

    /**
     * What a rebuild would do, without changing anything.
     *
     * @return array{feed: string, rows: int, watermark: ?string, from: string, to: string, days: int, interrupted: bool}
     */
    public function plan(FeedDefinition $feed, int $days, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $to = $now->subMinute();
        $watermark = $this->state->get("scores:{$feed->name}:watermark");

        return [
            'feed' => $feed->name,
            'rows' => $this->scores->count($feed->name),
            'watermark' => $watermark !== null ? CarbonImmutable::createFromTimestamp((int) $watermark)->toDateTimeString() : null,
            'from' => $to->subDays($days)->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'days' => $days,
            'interrupted' => $this->state->get(self::markerKey($feed->name)) !== null,
        ];
    }

    /**
     * @return array{deleted: int, from: string, to: string, events: int, items: int}
     */
    public function run(FeedDefinition $feed, int $days, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $this->state->put(self::markerKey($feed->name), (string) $now->getTimestamp());

        $deleted = $this->scores->resetFeed($feed->name, $feed->int('delete_chunk', 5000));
        $this->state->put("scores:{$feed->name}:watermark", null);

        $window = FeedDefinition::make($feed->name, $feed->toArray(), ['scores_backfill_days' => max(1, $days)]);
        $result = $this->updater->run($window, $now, rebuilding: true);

        $this->state->put(self::markerKey($feed->name), null);

        return ['deleted' => $deleted] + $result;
    }
}
