<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Maintenance\NeighbourBuilder;
use SolutionForest\Recommender\Maintenance\ScoreUpdater;
use SolutionForest\Recommender\Recommender;

/*
 * Stress tests on the config-only feed (examples/simple) with real tables.
 *
 *  1. Data volume — 100k interactions, 2k users, 1k items: every event is
 *     counted, memory and query counts stay bounded, serving cost does not
 *     grow with history.
 *  2. Interaction frequency — events arriving every minute (bursts, events
 *     on slice boundaries, inside the safety margin) and pages requested in
 *     rapid succession: nothing lost, nothing counted twice, no repeats,
 *     rebuilds throttled.
 *
 * Scale up locally: RECOMMENDER_STRESS_SCALE=5 vendor/bin/pest --group=stress
 */

const STRESS_CLUSTERS = 50;
const STRESS_CLUSTER_SIZE = 20;

function stressScale(): int
{
    return max(1, (int) (getenv('RECOMMENDER_STRESS_SCALE') ?: 1));
}

/** Count queries (optionally only those touching $table) while running $fn. */
function countQueries(callable $fn, ?string $table = null): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count, $table) {
        if ($table === null || str_contains($query->sql, $table)) {
            $count++;
        }
    });
    $fn();
    DB::flushQueryLog();

    return $count;
}

/** @param list<array<string, mixed>> $rows */
function bulkInsert(string $table, array $rows): void
{
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table($table)->insert($chunk);
    }
}

/**
 * Users like items inside "their" cluster and view items anywhere, spread
 * over the last 9 days. Returns [likes, views].
 *
 * @return array{0: int, 1: int}
 */
function seedVolume(CarbonImmutable $now, int $users, int $likesPerUser, int $viewsPerUser): array
{
    mt_srand(42);
    $items = STRESS_CLUSTERS * STRESS_CLUSTER_SIZE;
    $stamp = fn () => $now->subSeconds(mt_rand(120, 9 * 86400))->toDateTimeString();

    $likes = $views = [];
    for ($u = 1; $u <= $users; $u++) {
        $cluster = $u % STRESS_CLUSTERS;
        for ($k = 0; $k < $likesPerUser; $k++) {
            $likes[] = ['user_id' => $u, 'article_id' => $cluster * STRESS_CLUSTER_SIZE + mt_rand(1, STRESS_CLUSTER_SIZE), 'created_at' => $stamp()];
        }
        for ($k = 0; $k < $viewsPerUser; $k++) {
            $views[] = ['user_id' => $u, 'article_id' => mt_rand(1, $items), 'created_at' => $stamp()];
        }
    }
    bulkInsert('article_likes', $likes);
    bulkInsert('article_views', $views);

    return [count($likes), count($views)];
}

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    $feeds = $example['feeds'];
    $feeds['articles'] += ['page_size' => 10, 'scores_backfill_days' => 10, 'scores_slice_minutes' => 1440];
    config(['recommender.feeds' => $feeds]);
    $this->app->forgetInstance(Recommender::class);

    $articles = [];
    foreach (range(1, STRESS_CLUSTERS * STRESS_CLUSTER_SIZE) as $i) {
        $articles[] = ['id' => $i, 'author_id' => 10_000 + $i, 'title' => "Article {$i}", 'published' => true];
    }
    bulkInsert('articles', $articles);

    $this->now = CarbonImmutable::parse('2026-11-08 12:00:00');
    $this->feed = fn () => app(Recommender::class)->feed('articles');
});

// -------------------------------------------------------------------------
// 1. Data volume
// -------------------------------------------------------------------------

it('folds 100k interactions into scores exactly, with bounded memory and queries', function () {
    $users = 2000 * stressScale();
    [$likes, $views] = seedVolume($this->now, $users, likesPerUser: 10, viewsPerUser: 40);
    $memoryBefore = memory_get_usage(true);

    $result = null;
    $t = hrtime(true);
    $queries = countQueries(function () use (&$result) {
        $result = app(ScoreUpdater::class)->run(($this->feed)(), $this->now);
    });
    $seconds = (hrtime(true) - $t) / 1e9;
    perfNote(sprintf('update-scores: %s events (%s users) in %.2fs = %s ev/s, %d queries, +%d MB',
        number_format($likes + $views), number_format($users), $seconds, number_format((int) (($likes + $views) / max($seconds, 0.001))), $queries, (memory_get_peak_usage(true) - $memoryBefore) / 1048576));

    $totals = DB::table('rec_item_scores')->where('feed', 'articles')
        ->selectRaw('count(*) as items, sum(positive) as positive, sum(exposure) as exposure')->first();

    expect($result['events'])->toBe($likes + $views)
        ->and((int) $totals->positive)->toBe($likes)
        ->and((int) $totals->exposure)->toBe($views)
        ->and((int) $totals->items)->toBe(STRESS_CLUSTERS * STRESS_CLUSTER_SIZE)
        // Streaming + bulk upserts: no query per event, memory not proportional to events.
        ->and($queries)->toBeLessThan(intdiv($likes + $views, 100))
        ->and((memory_get_peak_usage(true) - $memoryBefore) / 1048576)->toBeLessThan(96);
})->group('stress');

it('builds neighbours for thousands of users within the configured caps', function () {
    $users = 2000 * stressScale();
    seedVolume($this->now, $users, likesPerUser: 10, viewsPerUser: 40);

    $result = null;
    $t = hrtime(true);
    $reads = countQueries(function () use (&$result) {
        $result = app(NeighbourBuilder::class)->run(($this->feed)(), $this->now);
    }, 'article_');
    perfNote(sprintf('build-neighbours: %s users, 1,000 items in %.2fs, %d interaction-table reads', number_format($users), (hrtime(true) - $t) / 1e9, $reads));

    $items = range(1, STRESS_CLUSTERS * STRESS_CLUSTER_SIZE);
    $graph = app(\SolutionForest\Recommender\Storage\NeighbourStore::class)->neighboursOf('articles', $items);
    $perItem = collect($graph)->map(fn (array $n) => count($n));

    expect($result['users'])->toBe($users)
        ->and($perItem->max())->toBeLessThanOrEqual(30) // neighbours_max
        ->and($perItem->count())->toBe(count($items))
        // Users are read in chunks of 500 — a few queries per chunk, never per user.
        ->and($reads)->toBeLessThanOrEqual((int) ceil($users / 500) * 3 + 3);

    // The strongest neighbours stay inside the cluster users liked together.
    $top = array_slice(array_column($graph[1], 0), 0, 10);
    expect(collect($top)->every(fn ($id) => $id <= STRESS_CLUSTER_SIZE))->toBeTrue();
})->group('stress');

it('serves personal pages at a constant query cost regardless of history size', function () {
    seedVolume($this->now, 2000 * stressScale(), likesPerUser: 10, viewsPerUser: 40);
    app(ScoreUpdater::class)->run(($this->feed)(), $this->now);
    app(NeighbourBuilder::class)->run(($this->feed)(), $this->now);
    $recommender = app(Recommender::class);

    $build = $cached = $coldMs = $warmMs = [];
    foreach (range(1, 200) as $u) {
        $user = new GenericUser(['id' => $u]);
        $page = null;
        $t = hrtime(true);
        $build[] = countQueries(function () use ($recommender, $user, &$page) {
            $page = $recommender->page('articles', $user, 1);
        });
        $coldMs[] = (hrtime(true) - $t) / 1e6;
        expect($page->origin)->toBe('personal')->and($page->items)->toHaveCount(10);

        $t = hrtime(true);
        $cached[] = countQueries(fn () => $recommender->page('articles', $user, 2));
        $warmMs[] = (hrtime(true) - $t) / 1e6;
    }
    perfNote(sprintf('page (200 users): cold build p50 %.1f ms / p95 %.1f ms, max %d queries; warm page p50 %.1f ms / p95 %.1f ms, max %d queries',
        percentile($coldMs, 50), percentile($coldMs, 95), max($build), percentile($warmMs, 50), percentile($warmMs, 95), max($cached)));

    // Cold build: recent items + neighbours + scores + filter + cards + groups.
    expect(max($build))->toBeLessThanOrEqual(12)
        // Warm list: at most one card query for ids not rendered yet.
        ->and(max($cached))->toBeLessThanOrEqual(2);
})->group('stress');

it('serves similar items at the same query cost and latency with 5x the users', function () {
    $measure = function (int $users): array {
        DB::table('article_likes')->delete();
        DB::table('article_views')->delete();
        cache()->flush();
        seedVolume($this->now, $users, likesPerUser: 10, viewsPerUser: 40);
        app(NeighbourBuilder::class)->run(($this->feed)(), $this->now);

        $items = range(1, STRESS_CLUSTERS * STRESS_CLUSTER_SIZE, 5); // 200 items
        $article = fn (int $id) => (new Article)->forceFill(['id' => $id])->syncOriginal();
        app(Recommender::class)->similar('articles', 1); // the neighbour version, read once per feed

        $cold = $warm = $coldMs = $warmMs = [];
        foreach ($items as $id) {
            $t = hrtime(true);
            $cold[] = countQueries(function () use ($article, $id) {
                expect($article($id)->similar(6))->toHaveCount(6);
            });
            $coldMs[] = (hrtime(true) - $t) / 1e6;

            $t = hrtime(true);
            $warm[] = countQueries(fn () => $article($id)->similar(6));
            $warmMs[] = (hrtime(true) - $t) / 1e6;
        }

        return ['cold' => max($cold), 'warm' => max($warm), 'cold_p95' => percentile($coldMs, 95), 'warm_p95' => percentile($warmMs, 95)];
    };

    $small = $measure(2000 * stressScale());
    $large = $measure(10000 * stressScale());
    perfNote(sprintf('similar (200 items): %s users cold p95 %.2f ms / warm p95 %.2f ms; %s users cold p95 %.2f ms / warm p95 %.2f ms; queries cold %d / warm %d',
        number_format(2000 * stressScale()), $small['cold_p95'], $small['warm_p95'], number_format(10000 * stressScale()), $large['cold_p95'], $large['warm_p95'], $large['cold'], $large['warm']));

    // One neighbours row + one filter query + one card query cold, cache only warm — at both sizes.
    expect($small['cold'])->toBeLessThanOrEqual(3)
        ->and($large['cold'])->toBe($small['cold'])
        ->and($small['warm'])->toBe(0)
        ->and($large['warm'])->toBe(0)
        // Generous bound: what matters is that it does not scale with users.
        ->and($large['cold_p95'])->toBeLessThan($small['cold_p95'] * 3 + 5);
})->group('stress');

// -------------------------------------------------------------------------
// 2. Interaction frequency
// -------------------------------------------------------------------------

it('counts every event exactly once when events arrive every minute and scores run every minute', function () {
    config(['recommender.feeds.articles.scores_slice_minutes' => 1]);
    $this->app->forgetInstance(Recommender::class);
    $updater = app(ScoreUpdater::class);
    $start = $this->now;

    // Start the watermark just before the stream begins.
    app(\SolutionForest\Recommender\Storage\StateStore::class)->put('scores:articles:watermark', (string) $start->subMinute()->getTimestamp());
    $inserted = ['like' => 0, 'view' => 0];
    $counted = 0;

    for ($minute = 1; $minute <= 120; $minute++) {
        $at = $start->addMinutes($minute);
        $rows = ['article_likes' => [], 'article_views' => []];

        // Every minute: events exactly on the minute (a slice boundary), mid-minute,
        // and one just before "now" (inside the 1-minute safety margin).
        foreach ([0, 17, 59] as $second) {
            $rows['article_likes'][] = ['user_id' => $minute, 'article_id' => 1 + $minute % 5, 'created_at' => $at->subMinute()->addSeconds($second)];
            $rows['article_views'][] = ['user_id' => $minute, 'article_id' => 1 + $minute % 5, 'created_at' => $at->subSeconds(1)];
        }
        if ($minute === 60) {
            // Burst: one user hammers one item 1,000 times within the same second.
            foreach (range(1, 1000) as $_) {
                $rows['article_likes'][] = ['user_id' => 7, 'article_id' => 3, 'created_at' => $at->subSeconds(30)];
            }
        }
        foreach ($rows as $table => $batch) {
            bulkInsert($table, array_map(fn ($r) => ['created_at' => $r['created_at']->toDateTimeString()] + $r, $batch));
        }
        $inserted['like'] += count($rows['article_likes']);
        $inserted['view'] += count($rows['article_views']);

        $counted += $updater->run(($this->feed)(), $at)['events'];
    }

    // Flush the safety margin.
    $counted += $updater->run(($this->feed)(), $start->addMinutes(125))['events'];
    perfNote(sprintf('every-minute stream: 120 score runs, %s events (incl. a 1,000-like burst in one second), counted %s', number_format(array_sum($inserted)), number_format($counted)));

    $totals = DB::table('rec_item_scores')->where('feed', 'articles')
        ->selectRaw('sum(positive) as positive, sum(exposure) as exposure')->first();

    expect($counted)->toBe($inserted['like'] + $inserted['view'])
        ->and((int) $totals->positive)->toBe($inserted['like'])
        ->and((int) $totals->exposure)->toBe($inserted['view'])
        ->and((int) DB::table('rec_item_scores')->where('feed', 'articles')->where('item_id', '3')->value('positive'))
        ->toBeGreaterThanOrEqual(1000);

    // The burst item now leads trending.
    expect(array_key_first(app(\SolutionForest\Recommender\Storage\ScoreStore::class)->hottest('articles', 5)))->toBe(3);
})->group('stress');

it('handles rapid-fire page requests without repeats and with throttled rebuilds', function () {
    seedVolume($this->now, 300, likesPerUser: 10, viewsPerUser: 40);
    app(ScoreUpdater::class)->run(($this->feed)(), $this->now);
    app(NeighbourBuilder::class)->run(($this->feed)(), $this->now);
    config(['recommender.feeds.articles.seen_max' => 2000]);
    $this->app->forgetInstance(Recommender::class);
    $recommender = app(Recommender::class);
    $user = new GenericUser(['id' => 1]);

    // Scroll as fast as possible: 60 pages back to back.
    $served = [];
    $t = hrtime(true);
    $builds = countQueries(function () use ($recommender, $user, &$served) {
        for ($page = 1; $page <= 60; $page++) {
            array_push($served, ...$recommender->page('articles', $user, $page)->ids);
        }
    }, 'rec_item_neighbours');
    $scrollMs = (hrtime(true) - $t) / 1e6;

    expect(count($served))->toBe(count(array_unique($served))) // no repeats while scrolling
        ->and(count($served))->toBeGreaterThan(200)            // personal list, then trending top-up
        ->and($builds)->toBe(1);                               // one build for the whole scroll

    // Hammer page 1 (pull-to-refresh) 100 times within the rebuild interval.
    $t = hrtime(true);
    $rebuilds = countQueries(function () use ($recommender, $user) {
        foreach (range(1, 100) as $_) {
            expect($recommender->page('articles', $user, 1)->items)->not->toBeEmpty();
        }
    }, 'rec_item_neighbours');
    perfNote(sprintf('rapid fire: 60 pages back to back in %.0f ms (%.1f ms/page), %d items, 0 repeats, %d build; 100× page 1 in %.0f ms, %d rebuilds',
        $scrollMs, $scrollMs / 60, count($served), $builds, (hrtime(true) - $t) / 1e6, $rebuilds));

    expect($rebuilds)->toBeLessThanOrEqual(1); // min_rebuild_interval throttles exhausted-list rebuilds
})->group('stress');

it('keeps cards and lists consistent while items change between requests', function () {
    seedVolume($this->now, 300, likesPerUser: 10, viewsPerUser: 40);
    app(ScoreUpdater::class)->run(($this->feed)(), $this->now);
    app(NeighbourBuilder::class)->run(($this->feed)(), $this->now);
    $recommender = app(Recommender::class);
    $user = new GenericUser(['id' => 1]);

    $first = $recommender->page('articles', $user, 1);
    $upcoming = $recommender->build('articles', $user);

    // Rapid edits: unpublish the next 20 queued items and retitle the shown ones.
    Article::query()->whereIn('id', array_slice(array_diff($upcoming, $first->ids), 0, 20))->each(fn (Article $a) => $a->update(['published' => false]));
    Article::query()->whereIn('id', $first->ids)->each(fn (Article $a) => $a->update(['title' => 'Edited']));

    $hidden = Article::query()->where('published', false)->pluck('id')->all();
    foreach (range(2, 6) as $page) {
        $next = $recommender->page('articles', $user, $page);
        expect(array_intersect($next->ids, $hidden))->toBeEmpty() // unpublished never rendered
            ->and($next->items)->toHaveCount(10);                  // page still filled
    }

    $recommender->forgetUser('articles', $user);
    $again = collect($recommender->page('articles', $user, 1)->items)->keyBy('id');
    foreach (array_intersect($first->ids, $again->keys()->all()) as $id) {
        expect($again[$id]['title'])->toBe('Edited'); // Recommendable trait refreshed the card
    }
})->group('stress');
