<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Recommender\Maintenance\NeighbourBuilder;
use SolutionForest\Recommender\Maintenance\ScoreUpdater;
use SolutionForest\Recommender\Recommender;

/*
 * HEAVY stress test — not part of the normal run. Enable with:
 *
 *   RECOMMENDER_HEAVY=1 vendor/bin/pest --group=heavy
 *
 * Defaults: 1,000,000 interactions (views + likes + shares) from 500,000
 * users on 5,000 items, ALL inside one 10-minute window ("the same time"),
 * then more bursts arriving while scores keep updating and people keep
 * loading feeds. It fails on any exception, any warning-or-worse log line,
 * a wrong count, a repeated item or a runaway query count / memory use.
 *
 * Tune: RECOMMENDER_HEAVY_EVENTS, RECOMMENDER_HEAVY_USERS.
 */

function heavy(string $name, int $default): int
{
    return max(1, (int) (getenv($name) ?: $default));
}

function heavyNote(string $line): void
{
    fwrite(STDERR, "[heavy] {$line}\n");
}

beforeEach(function () {
    ini_set('memory_limit', '3G');
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    Schema::dropIfExists('article_shares');
    Schema::create('article_shares', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('article_id');
        $table->timestamp('created_at')->nullable()->index();
        $table->index(['user_id', 'created_at']);
    });

    config(['recommender.feeds' => ['articles' => [
        'model' => SolutionForest\Recommender\Examples\Simple\Models\Article::class,
        'interactions' => ['article_likes' => 'like', 'article_views' => 'view', 'article_shares' => 'share'],
        'events' => ['view' => 1, 'like' => 4, 'share' => 6],
        'positive_events' => ['like', 'share'],
        'exposure_events' => ['view'],
        'diversity' => ['author_id' => 1],
        'page_size' => 12,
        'scores_backfill_days' => 1,
        'scores_slice_minutes' => 10,
    ]]]);
    $this->app->forgetInstance(Recommender::class);

    $this->problems = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) {
        if (in_array($log->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            $this->problems[] = "{$log->level}: {$log->message}";
        }
    });
});

afterEach(function () {
    Schema::dropIfExists('article_shares');
});

/**
 * Insert $count random events of one table inside [$from, $from + $seconds).
 * Returns how many were inserted.
 */
function heavySeed(string $table, int $count, int $users, int $items, CarbonImmutable $from, int $seconds): int
{
    $rows = [];
    $done = 0;
    while ($done < $count) {
        $rows = [];
        $n = min(2000, $count - $done);
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'user_id' => mt_rand(1, $users),
                // A few very hot items plus a long tail, like real traffic.
                'article_id' => mt_rand(1, 10) === 1 ? mt_rand(1, 20) : mt_rand(1, $items),
                'created_at' => $from->addSeconds(mt_rand(0, $seconds - 1))->toDateTimeString(),
            ];
        }
        DB::table($table)->insert($rows);
        $done += $n;
    }

    return $done;
}

it('absorbs 1M mixed interactions from 500k users at the same time without a query error or log error', function () {
    mt_srand(2026);
    $events = heavy('RECOMMENDER_HEAVY_EVENTS', 1_000_000);
    $users = heavy('RECOMMENDER_HEAVY_USERS', 500_000);
    $items = 5000;
    $now = CarbonImmutable::parse('2026-11-12 12:00:00');
    CarbonImmutable::setTestNow($now);

    DB::table('articles')->insert([]);
    foreach (array_chunk(range(1, $items), 1000) as $ids) {
        DB::table('articles')->insert(array_map(fn ($i) => ['id' => $i, 'author_id' => 1 + $i % 800, 'title' => "A{$i}", 'published' => true], $ids));
    }

    // ---- 1. The burst: everything inside ten minutes ------------------------------------
    $t = hrtime(true);
    $window = $now->subMinutes(12);
    $views = heavySeed('article_views', (int) ($events * 0.6), $users, $items, $window, 600);
    $likes = heavySeed('article_likes', (int) ($events * 0.3), $users, $items, $window, 600);
    $shares = heavySeed('article_shares', (int) ($events * 0.1), $users, $items, $window, 600);
    heavyNote(sprintf('seeded %s events (%s views, %s likes, %s shares) in %.1fs', number_format($views + $likes + $shares), number_format($views), number_format($likes), number_format($shares), (hrtime(true) - $t) / 1e9));

    $recommender = app(Recommender::class);
    $feed = $recommender->feed('articles');
    $memory = memory_get_usage(true);

    // ---- 2. Fold them into scores -------------------------------------------------------
    $t = hrtime(true);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $result = app(ScoreUpdater::class)->run($feed, $now);
    $seconds = (hrtime(true) - $t) / 1e9;
    heavyNote(sprintf('update-scores: %s events in %.1fs (%s ev/s), %d queries, peak +%d MB', number_format($result['events']), $seconds, number_format((int) ($result['events'] / max($seconds, 0.001))), $queries, (memory_get_peak_usage(true) - $memory) / 1048576));

    expect($result['events'])->toBe($views + $likes + $shares)
        ->and($queries)->toBeLessThan(intdiv($views + $likes + $shares, 100) + 2000) // per slice, never per event
        ->and((memory_get_peak_usage(true) - $memory) / 1048576)->toBeLessThan(256);

    // ---- 3. Neighbours across all users -------------------------------------------------
    $t = hrtime(true);
    $built = app(NeighbourBuilder::class)->run($feed, $now);
    heavyNote(sprintf('build-neighbours: %s users in %.1fs', number_format($built['users']), (hrtime(true) - $t) / 1e9));
    expect($built['users'])->toBeGreaterThan(0);

    // ---- 4. Keep going: bursts + score runs + people loading feeds, interleaved ----------
    $liveViews = $liveLikes = $liveShares = 0;
    $served = [];
    $cold = [];
    $meter = new stdClass;
    $meter->n = 0;
    DB::listen(function () use ($meter) {
        $meter->n++;
    });
    for ($minute = 1; $minute <= 6; $minute++) {
        $at = $now->addMinutes($minute);
        CarbonImmutable::setTestNow($at);
        $liveViews += heavySeed('article_views', 6000, $users, $items, $at->subMinute(), 60);
        $liveLikes += heavySeed('article_likes', 3000, $users, $items, $at->subMinute(), 60);
        $liveShares += heavySeed('article_shares', 1000, $users, $items, $at->subMinute(), 60);

        app(ScoreUpdater::class)->run($feed, $at);

        // 150 members (new and returning) and 20 guests load the feed right after the update.
        for ($i = 0; $i < 150; $i++) {
            $id = mt_rand(1, $users);
            $user = new GenericUser(['id' => $id]);
            $meter->n = 0;
            foreach ([1, 2] as $page) {
                $p = $recommender->page('articles', $user, $page);
                expect($p->items)->not->toBeEmpty();
                $served[$id] = [...($served[$id] ?? []), ...$p->ids];
            }
            $cold[] = $meter->n;
        }
        for ($i = 0; $i < 20; $i++) {
            expect($recommender->page('articles', null, 1)->items)->not->toBeEmpty();
        }
        // Mid-way, the neighbour graph is rebuilt while pages keep being served.
        if ($minute === 3) {
            app(NeighbourBuilder::class)->run($feed, $at);
        }
    }

    // Flush the score safety margin, then check every count exactly.
    CarbonImmutable::setTestNow($now->addMinutes(10));
    app(ScoreUpdater::class)->run($feed, $now->addMinutes(10));

    $totals = DB::table('rec_item_scores')->where('feed', 'articles')
        ->selectRaw('sum(positive) as positive, sum(exposure) as exposure')->first();

    expect((int) $totals->positive)->toBe($likes + $shares + $liveLikes + $liveShares)
        ->and((int) $totals->exposure)->toBe($views + $liveViews);

    // Nobody was served the same item twice while scrolling.
    foreach ($served as $id => $ids) {
        expect(count($ids))->toBe(count(array_unique($ids)));
    }
    // Serving cost stays bounded no matter how much data is behind it.
    expect(max($cold))->toBeLessThanOrEqual(40);

    // The hot items lead trending.
    $hottest = array_keys(app(SolutionForest\Recommender\Storage\ScoreStore::class)->hottest('articles', 10));
    expect(count(array_filter($hottest, fn ($id) => $id <= 20)))->toBeGreaterThanOrEqual(5);

    heavyNote(sprintf('served pages: max %d queries per user; peak memory %d MB', max($cold), memory_get_peak_usage(true) / 1048576));

    // ---- 5. The point of the exercise: no errors anywhere -------------------------------
    expect($this->problems)->toBe([]);
    CarbonImmutable::setTestNow();
})->group('heavy')->skip(! getenv('RECOMMENDER_HEAVY'), 'set RECOMMENDER_HEAVY=1 to run the 1M-event stress test');
