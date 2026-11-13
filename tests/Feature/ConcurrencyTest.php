<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Recommender as RecommenderService;

/*
 * Opt-in concurrency test: many processes serve feeds at the same moment on
 * a real database, with the *database* cache store and impressions on, while
 * one process keeps adding likes and folding them into scores.
 *
 *   RECOMMENDER_CONCURRENCY=1 RECOMMENDER_TEST_DB=mysql DB_HOST=… vendor/bin/pest --group=concurrency
 *
 * Tune: RECOMMENDER_CONCURRENCY_WORKERS (default 12), RECOMMENDER_CONCURRENCY_REQUESTS (per worker, default 150).
 * Fails on any exception, deadlock or warning-or-worse log line in any process.
 */
it('serves many processes at once on the database cache without deadlocks or errors', function () {
    $workers = (int) (getenv('RECOMMENDER_CONCURRENCY_WORKERS') ?: 12);
    $requests = (int) (getenv('RECOMMENDER_CONCURRENCY_REQUESTS') ?: 150);
    $users = 300; // small pool on purpose: the same user is served by several processes at once

    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    Schema::dropIfExists('cache');
    Schema::dropIfExists('cache_locks');
    Schema::create('cache', function ($t) { $t->string('key')->primary(); $t->mediumText('value'); $t->integer('expiration'); });
    Schema::create('cache_locks', function ($t) { $t->string('key')->primary(); $t->string('owner'); $t->integer('expiration'); });
    config([
        'cache.stores.database' => ['driver' => 'database', 'connection' => config('database.default'), 'table' => 'cache', 'lock_connection' => config('database.default'), 'lock_table' => 'cache_locks'],
        'recommender.cache_store' => 'database',
        'recommender.feeds' => ['articles' => [
            'model' => Article::class, 'interactions' => ['article_likes' => 'like', 'article_views' => 'view'],
            'impressions' => true, 'page_size' => 10, 'feed_ttl' => 5, 'min_rebuild_interval' => 0,
        ]],
    ]);
    app()->forgetInstance(FeedCache::class);
    app()->forgetInstance(RecommenderService::class);

    mt_srand(7);
    $articles = [];
    foreach (range(1, 400) as $i) {
        $articles[] = ['id' => $i, 'author_id' => 10_000 + $i, 'title' => "A{$i}", 'published' => true];
    }
    DB::table('articles')->insert($articles);
    $likes = [];
    foreach (range(1, 3000) as $u) {
        foreach (range(1, 6) as $_) {
            $likes[] = ['user_id' => $u, 'article_id' => ($u % 20) * 20 + mt_rand(1, 20), 'created_at' => now()->subHours(2)];
        }
    }
    foreach (array_chunk($likes, 1000) as $chunk) {
        DB::table('article_likes')->insert($chunk);
    }
    $this->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'articles'])->assertSuccessful();

    $dir = sys_get_temp_dir().'/rec-concurrency-'.getmypid();
    @mkdir($dir);
    DB::disconnect(); // children open their own connections

    $started = microtime(true);
    $pids = [];
    for ($w = 0; $w < $workers; $w++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $errors = [];
            DB::purge();
            Log::listen(function ($message) use (&$errors) {
                if (in_array($message->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                    $errors[] = "log {$message->level}: {$message->message}";
                }
            });
            mt_srand(1000 + $w);
            for ($r = 0; $r < $requests; $r++) {
                try {
                    if ($w === 0 && $r % 25 === 0) {
                        // Writer: new likes arrive and are folded into scores while others read.
                        DB::table('article_likes')->insert(['user_id' => mt_rand(1, $users), 'article_id' => mt_rand(1, 400), 'created_at' => now()->subMinutes(2)]);
                        app(\SolutionForest\Recommender\Maintenance\ScoreUpdater::class)->run(app(RecommenderService::class)->feed('articles'));
                    }
                    $user = mt_rand(0, 9) === 0 ? null : new GenericUser(['id' => mt_rand(1, $users)]);
                    Recommender::page('articles', $user, mt_rand(1, 3));
                    app()->terminate(); // run the deferred impression writes, as at the end of an HTTP request
                } catch (Throwable $e) {
                    $errors[] = get_class($e).': '.$e->getMessage();
                }
            }
            file_put_contents("{$dir}/{$w}.json", json_encode($errors));
            posix_kill(getmypid(), SIGKILL); // skip PHPUnit shutdown in the child
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $seconds = microtime(true) - $started;

    DB::reconnect();
    $errors = [];
    foreach (glob("{$dir}/*.json") ?: [] as $file) {
        array_push($errors, ...(json_decode((string) file_get_contents($file), true) ?: []));
        unlink($file);
    }
    @rmdir($dir);

    $total = $workers * $requests;
    $deadlocks = count(array_filter($errors, fn ($e) => stripos($e, 'deadlock') !== false));
    fwrite(STDERR, sprintf("\n[concurrency] %d workers × %d requests = %d pages in %.1fs (%.0f pages/s); errors: %d (deadlocks: %d); impressions: %d; cache rows: %d\n",
        $workers, $requests, $total, $seconds, $total / max(0.001, $seconds), count($errors), $deadlocks,
        (int) DB::table('rec_item_scores')->sum('impressions'), DB::table('cache')->count()));

    expect($errors)->toBe([])
        ->and((int) DB::table('rec_item_scores')->sum('impressions'))->toBeGreaterThan(0);
})->group('concurrency')->skip(
    ! getenv('RECOMMENDER_CONCURRENCY') || getenv('RECOMMENDER_TEST_DB') !== 'mysql' || ! function_exists('pcntl_fork'),
    'set RECOMMENDER_CONCURRENCY=1 with RECOMMENDER_TEST_DB=mysql (needs pcntl)'
);
