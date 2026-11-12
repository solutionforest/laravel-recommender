<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Maintenance\NeighbourBuilder;
use SolutionForest\Recommender\Maintenance\ScoreUpdater;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Sources\CoInteractionSource;
use SolutionForest\Recommender\Storage\NeighbourStore;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Tests\Fixtures\ArrayInteractions;

it('folds interactions into scores incrementally', function () {
    $feed = app(Recommender::class)->feed('reviews');
    $now = CarbonImmutable::parse('2026-11-08 12:00:00');

    ArrayInteractions::add(1, 10, 'view', '2026-11-01 10:00');
    ArrayInteractions::add(2, 10, 'view', '2026-11-01 11:00');
    ArrayInteractions::add(2, 10, 'like', '2026-11-01 11:01');
    ArrayInteractions::add(1, 20, 'view', '2026-11-08 11:00');
    ArrayInteractions::add(1, 30, 'unknown', '2026-11-08 11:00');

    $first = app(ScoreUpdater::class)->run($feed, $now);
    // Item 10 has events in two hourly slices -> upserted once per slice.
    expect($first['events'])->toBe(4)->and($first['items'])->toBe(3);

    $row = DB::table('rec_item_scores')->where('item_id', '10')->first();
    expect((int) $row->positive)->toBe(1)->and((int) $row->exposure)->toBe(2);

    // Second run only reads events after the watermark.
    ArrayInteractions::add(3, 10, 'like', '2026-11-08 12:05');
    $second = app(ScoreUpdater::class)->run($feed, $now->addMinutes(10));
    expect($second['events'])->toBe(1);

    $row = DB::table('rec_item_scores')->where('item_id', '10')->first();
    expect((int) $row->positive)->toBe(2)->and((int) $row->exposure)->toBe(2);
    expect(DB::table('rec_item_scores')->count())->toBe(2);
});

it('ranks trending by time-decayed popularity', function () {
    $feed = app(Recommender::class)->feed('reviews');

    // Item 1: 5 likes 24 days ago (5*4*2^(-24/7) ≈ 1.9). Item 2: 3 views today (≈ 3).
    foreach (range(1, 5) as $u) {
        ArrayInteractions::add($u, 1, 'like', '2026-10-15 10:00');
    }
    foreach (range(1, 3) as $u) {
        ArrayInteractions::add($u, 2, 'view', '2026-11-08 10:00');
    }
    app(ScoreUpdater::class)->run($feed, CarbonImmutable::parse('2026-11-08 12:00'));

    $hot = app(ScoreStore::class)->hottest('reviews', 10);

    expect(array_keys($hot))->toBe([2, 1])
        ->and($hot[2])->toBe(1.0);
});

it('lets event weights decide whether likes or views count more toward hot', function () {
    $now = CarbonImmutable::parse('2026-11-08 12:00:00');

    // Same moment: item 10 = one like, item 20 = one view.
    ArrayInteractions::add(1, 10, 'like', '2026-11-08 10:00');
    ArrayInteractions::add(2, 20, 'view', '2026-11-08 10:00');

    $this->configureFeed(['events' => ['view' => 1.0, 'like' => 4.0]]);
    app(ScoreUpdater::class)->run(app(Recommender::class)->feed('reviews'), $now);
    expect(array_keys(app(ScoreStore::class)->hottest('reviews', 10)))->toBe([10, 20]);

    // Rebuild from scratch with views weighted higher than likes.
    DB::table('rec_item_scores')->delete();
    DB::table('rec_state')->where('key', 'like', 'scores:reviews:%')->delete();
    ArrayInteractions::reset();
    ArrayInteractions::add(1, 10, 'like', '2026-11-08 10:00');
    ArrayInteractions::add(2, 20, 'view', '2026-11-08 10:00');

    $this->configureFeed(['events' => ['view' => 10.0, 'like' => 1.0]]);
    app(ScoreUpdater::class)->run(app(Recommender::class)->feed('reviews'), $now);
    expect(array_keys(app(ScoreStore::class)->hottest('reviews', 10)))->toBe([20, 10]);
});

it('builds bounded co-interaction neighbours and replaces old versions', function () {
    $feed = app(Recommender::class)->feed('reviews');
    // Users 1-3 liked 10 and 11; user 4 liked 10 and 12 (only once together).
    foreach ([1, 2, 3] as $u) {
        ArrayInteractions::add($u, 10, 'like', '2026-11-01');
        ArrayInteractions::add($u, 11, 'like', '2026-11-02');
    }
    ArrayInteractions::add(4, 10, 'like', '2026-11-01');
    ArrayInteractions::add(4, 12, 'like', '2026-11-02');

    $now = CarbonImmutable::parse('2026-11-08');
    $first = app(NeighbourBuilder::class)->run($feed, $now);
    $second = app(NeighbourBuilder::class)->run($feed, $now);

    expect($first['users'])->toBe(4);
    // min_co_count = 2 drops the 10<->12 pair.
    $neighbours = app(NeighbourStore::class)->neighboursOf('reviews', [10, 12]);
    expect($neighbours[10])->toHaveCount(1)
        ->and($neighbours[10][0][0])->toBe(11)
        ->and($neighbours[10][0][1])->toEqualWithDelta(3 / sqrt(4 * 3), 1e-4)
        ->and($neighbours)->not->toHaveKey(12);

    // Only the active version is kept.
    expect(DB::table('rec_item_neighbours')->distinct()->pluck('version')->all())->toBe([$second['version']]);
});

it('caps each user history when building neighbours', function () {
    $this->configureFeed(['history_per_user' => 2, 'neighbours_min_co_count' => 1]);
    $feed = app(Recommender::class)->feed('reviews');
    ArrayInteractions::add(1, 1, 'like', '2026-11-01');
    ArrayInteractions::add(1, 2, 'like', '2026-11-02');
    ArrayInteractions::add(1, 3, 'like', '2026-11-03');

    app(NeighbourBuilder::class)->run($feed, CarbonImmutable::parse('2026-11-08'));

    // Only the 2 most recent items (3, 2) are paired; item 1 is ignored.
    expect(DB::table('rec_item_neighbours')->pluck('item_id')->sort()->values()->all())->toBe(['2', '3']);
});

it('recommends neighbours of the user\'s recent items', function () {
    foreach ([1, 2, 3] as $u) {
        ArrayInteractions::add($u, 10, 'like', '2026-11-01');
        ArrayInteractions::add($u, 11, 'like', '2026-11-02');
    }
    ArrayInteractions::add(9, 10, 'view', now()->toDateTimeString());
    $feed = app(Recommender::class)->feed('reviews');
    app(NeighbourBuilder::class)->run($feed, CarbonImmutable::parse('2026-11-08'));

    $candidates = app(CoInteractionSource::class)->candidates(new Context($feed, 9), 10);

    expect(array_keys($candidates))->toBe([11]);
    expect(app(CoInteractionSource::class)->candidates(new Context($feed, null), 10))->toBe([]);
});
