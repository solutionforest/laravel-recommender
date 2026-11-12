<?php

namespace SolutionForest\Recommender\Maintenance;

use Carbon\CarbonImmutable;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Storage\StateStore;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Interaction;

/**
 * Incrementally folds new interactions into rec_item_scores.
 *
 * Only events since the last run are read (watermark in rec_state), so the
 * cost depends on how much happened since then, not on the size of the
 * interaction tables. Writes are bulk upserts, one per chunk of items.
 */
final class ScoreUpdater
{
    public function __construct(
        private readonly Bindings $bindings,
        private readonly ScoreStore $scores,
        private readonly StateStore $state,
    ) {
    }

    /**
     * @return array{from: string, to: string, events: int, items: int}  items = row upserts (per slice)
     */
    public function run(FeedDefinition $feed, ?CarbonImmutable $now = null): array
    {
        $source = $this->bindings->interactions($feed)
            ?? throw new \RuntimeException("Feed [{$feed->name}] has no 'interactions' configured.");

        $now ??= CarbonImmutable::now();
        // Leave a small margin for rows still being written.
        $to = $now->subMinute();
        $key = "scores:{$feed->name}:watermark";
        $stored = $this->state->get($key);
        $from = $stored !== null
            ? CarbonImmutable::createFromTimestamp((int) $stored)
            : $to->subDays($feed->int('scores_backfill_days', 30));

        if ($from->greaterThanOrEqualTo($to)) {
            return ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString(), 'events' => 0, 'items' => 0];
        }

        $sliceMinutes = max(1, $feed->int('scores_slice_minutes', 60));
        $events = 0;
        $items = 0;

        // Work through the window one slice at a time. Each slice's deltas and
        // its watermark are committed in one transaction, so memory stays
        // bounded by the items touched in one slice, a crash resumes after the
        // last committed slice, and no slice is ever counted twice.
        for ($sliceFrom = $from; $sliceFrom->lessThan($to); $sliceFrom = $sliceTo) {
            $sliceTo = $sliceFrom->addMinutes($sliceMinutes);
            if ($sliceTo->greaterThan($to)) {
                $sliceTo = $to;
            }

            [$deltas, $count] = $this->aggregate($feed, $source, $sliceFrom, $sliceTo);
            $events += $count;

            $this->scores->transaction(function () use ($feed, $deltas, $key, $sliceTo, &$items) {
                if ($deltas !== []) {
                    $items += $this->scores->merge($feed->name, $deltas, $feed->int('write_chunk', 500));
                }
                $this->state->put($key, (string) $sliceTo->getTimestamp());
            });
        }

        return ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString(), 'events' => $events, 'items' => $items];
    }

    /**
     * @return array{0: array<string, array{hot: ?float, positive: int, exposure: int, last: ?\DateTimeInterface}>, 1: int}
     */
    private function aggregate(FeedDefinition $feed, InteractionSource $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weights = (array) $feed->get('events', []);
        $positive = array_flip((array) $feed->get('positive_events', []));
        $exposure = array_flip((array) $feed->get('exposure_events', []));
        $halfLife = $feed->float('half_life_days', 7);

        $deltas = [];
        $events = 0;

        /** @var Interaction $interaction */
        foreach ($source->between($from, $to) as $interaction) {
            $weight = (float) ($weights[$interaction->event] ?? 0);
            if ($weight <= 0) {
                continue;
            }
            $events++;
            $id = (string) $interaction->itemId;
            $delta = $deltas[$id] ?? ['hot' => null, 'positive' => 0, 'exposure' => 0, 'last' => null];
            $delta['hot'] = Math::logAddExp($delta['hot'], Math::hotTerm($weight, $interaction->occurredAt, $halfLife));
            if (isset($positive[$interaction->event])) {
                $delta['positive']++;
            }
            if (isset($exposure[$interaction->event])) {
                $delta['exposure']++;
            }
            if ($delta['last'] === null || $interaction->occurredAt > $delta['last']) {
                $delta['last'] = $interaction->occurredAt;
            }
            $deltas[$id] = $delta;
        }

        return [$deltas, $events];
    }
}
