<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Maintenance\MemoryBudgetExceeded;
use SolutionForest\Recommender\Maintenance\NeighbourBuilder;
use SolutionForest\Recommender\Maintenance\ScoreUpdater;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Storage\NeighbourStore;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Storage\StateStore;
use SolutionForest\Recommender\Tests\Fixtures\ArrayInteractions;

/** Count write statements by type while running $fn. */
function countWrites(callable $fn): array
{
    $counts = ['insert' => 0, 'update' => 0, 'delete' => 0];
    DB::listen(function ($query) use (&$counts) {
        foreach (array_keys($counts) as $type) {
            if (str_starts_with(strtolower(ltrim($query->sql)), $type)) {
                $counts[$type]++;
            }
        }
    });
    $fn();

    return $counts;
}

function seedCoLikes(int $users, int $items): void
{
    // Every user likes a sliding window of 3 items -> many overlapping pairs.
    for ($u = 1; $u <= $users; $u++) {
        foreach ([0, 1, 2] as $k) {
            ArrayInteractions::add($u, (($u + $k) % $items) + 1, 'like', '2026-11-0'.($k + 1));
        }
    }
}

it('produces the same neighbours with any number of shards', function () {
    seedCoLikes(60, 20);
    $feed = app(Recommender::class)->feed('reviews');
    $now = CarbonImmutable::parse('2026-11-08');

    app(NeighbourBuilder::class)->run($feed, $now);
    $single = app(NeighbourStore::class)->neighboursOf('reviews', range(1, 20));

    app(StateStore::class)->put('neighbours:reviews:shards', '4');
    $result = app(NeighbourBuilder::class)->run($feed, $now);
    $sharded = app(NeighbourStore::class)->neighboursOf('reviews', range(1, 20));

    ksort($single);
    ksort($sharded);
    expect($result['shards'])->toBe(4)
        ->and($sharded)->toBe($single)
        ->and(count($single))->toBe(20);
});

it('escalates shards on memory pressure and never leaves a half-built version', function () {
    seedCoLikes(30, 10);
    $feed = app(Recommender::class)->feed('reviews');
    $now = CarbonImmutable::parse('2026-11-08');
    $good = app(NeighbourBuilder::class)->run($feed, $now);

    // A 1 MB budget can never be met: the job doubles shards up to the max, then gives up.
    $this->configureFeed(['neighbours_memory_limit_mb' => 1, 'neighbours_max_shards' => 4]);
    $feed = app(Recommender::class)->feed('reviews');

    expect(fn () => app(NeighbourBuilder::class)->run($feed, $now))->toThrow(MemoryBudgetExceeded::class);

    // The previous version is still active and complete; no partial rows remain.
    expect(app(NeighbourStore::class)->activeVersion('reviews'))->toBe($good['version'])
        ->and(DB::table('rec_item_neighbours')->distinct()->pluck('version')->all())->toBe([$good['version']])
        ->and(app(StateStore::class)->get('neighbours:reviews:shards'))->toBe('1');
});

it('caps the memory budget below php memory_limit', function () {
    expect(NeighbourBuilder::budgetBytes(512, '256M'))->toBe((int) (256 * 1048576 * 0.8))
        ->and(NeighbourBuilder::budgetBytes(512, '2G'))->toBe(512 * 1048576)
        ->and(NeighbourBuilder::budgetBytes(512, '-1'))->toBe(512 * 1048576);
});

it('writes neighbours with bulk inserts, not one row at a time', function () {
    seedCoLikes(200, 50);
    $this->configureFeed(['write_chunk' => 20]);
    $feed = app(Recommender::class)->feed('reviews');

    $writes = countWrites(fn () => app(NeighbourBuilder::class)->run($feed, CarbonImmutable::parse('2026-11-08')));
    $rows = DB::table('rec_item_neighbours')->count();

    // 50 items / 20 per insert = 3 inserts for neighbours (+1 state upsert).
    expect($rows)->toBe(50)
        ->and($writes['insert'])->toBeLessThanOrEqual((int) ceil($rows / 20) + 2);
});

it('merges scores with one upsert per chunk', function () {
    $deltas = [];
    foreach (range(1, 1200) as $id) {
        $deltas[$id] = ['hot' => 1.0, 'positive' => 1, 'exposure' => 2, 'last' => CarbonImmutable::parse('2026-11-08')];
    }

    $writes = countWrites(fn () => app(ScoreStore::class)->merge('reviews', $deltas, 500));

    expect(DB::table('rec_item_scores')->count())->toBe(1200)
        ->and($writes['insert'])->toBe(3); // upsert = INSERT ... ON CONFLICT, 1200 / 500 -> 3
});

it('resumes after a crash without counting any event twice', function () {
    $this->configureFeed(['scores_slice_minutes' => 60]);
    $feed = app(Recommender::class)->feed('reviews');
    $now = CarbonImmutable::parse('2026-11-08 12:00');
    app(StateStore::class)->put('scores:reviews:watermark', (string) $now->subHours(3)->getTimestamp());

    ArrayInteractions::add(1, 10, 'like', '2026-11-08 09:30'); // slice 1
    ArrayInteractions::add(2, 10, 'like', '2026-11-08 10:30'); // slice 2
    ArrayInteractions::add(3, 10, 'like', '2026-11-08 11:30'); // slice 3

    ArrayInteractions::$failOnCall = 2;
    expect(fn () => app(ScoreUpdater::class)->run($feed, $now))->toThrow(RuntimeException::class);

    expect((int) DB::table('rec_item_scores')->where('item_id', '10')->value('positive'))->toBe(1);

    ArrayInteractions::$failOnCall = null;
    app(ScoreUpdater::class)->run($feed, $now);

    expect((int) DB::table('rec_item_scores')->where('item_id', '10')->value('positive'))->toBe(3);
});

it('prunes idle score rows in chunks and keeps active ones', function () {
    $old = CarbonImmutable::parse('2026-01-01');
    $recent = CarbonImmutable::parse('2026-11-01');
    $deltas = [];
    foreach (range(1, 7) as $id) {
        $deltas[$id] = ['hot' => 1.0, 'positive' => 0, 'exposure' => 1, 'last' => $id <= 5 ? $old : $recent];
    }
    app(ScoreStore::class)->merge('reviews', $deltas);

    $writes = countWrites(function () use (&$deleted) {
        $deleted = app(ScoreStore::class)->prune('reviews', CarbonImmutable::parse('2026-05-01'), 2);
    });

    expect($deleted)->toBe(5)
        ->and(DB::table('rec_item_scores')->pluck('item_id')->sort()->values()->all())->toBe(['6', '7'])
        ->and($writes['delete'])->toBe(3); // 5 rows / chunk of 2
});

it('runs the prune command', function () {
    app(ScoreStore::class)->merge('reviews', [1 => ['hot' => 1.0, 'positive' => 0, 'exposure' => 1, 'last' => now()->subYear()]]);

    $this->artisan('recommender:prune')->assertSuccessful();

    expect(DB::table('rec_item_scores')->count())->toBe(0);
});

it('stores lists and seen-sets packed and still reads the old array format', function () {
    $feed = app(Recommender::class)->feed('reviews');
    $cache = app(FeedCache::class);

    $cache->putList($feed, 7, [11, 21, 31]);
    expect(Cache::get('rec:reviews:list:7'))->toMatch('/^\d+\|11,21,31$/')
        ->and($cache->list($feed, 7)['ids'])->toBe([11, 21, 31]);

    Cache::put('rec:reviews:seen:8', [1, 2]);
    expect($cache->seen($feed, 8))->toBe([1, 2]);

    // 200 six-digit ids packed: ~1.4 KB instead of ~2.9 KB serialized.
    $ids = range(100000, 100199);
    expect(strlen(FeedCache::pack($ids)))->toBeLessThan(1500)
        ->and(strlen(serialize($ids)))->toBeGreaterThan(2 * strlen(FeedCache::pack($ids)))
        ->and(FeedCache::unpack(FeedCache::pack($ids)))->toBe($ids);
});
