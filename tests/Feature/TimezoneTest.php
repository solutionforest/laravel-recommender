<?php

// update-scores stores its watermark as a Unix timestamp. Carbon 3 reads one
// back in UTC while interaction tables hold app-local times, which re-counted
// (east of UTC) or skipped (west of UTC) events before 0.10.5.

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Maintenance\ScoreUpdater;

beforeEach(function () {
    $this->originalTimezone = date_default_timezone_get();
});

afterEach(function () {
    date_default_timezone_set($this->originalTimezone);
    config(['app.timezone' => $this->originalTimezone]);
});

it('counts every interaction exactly once in any app timezone', function (string $tz) {
    date_default_timezone_set($tz);
    config(['app.timezone' => $tz]);
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);
    $feed = Recommender::feed('articles');

    Article::create(['id' => 1, 'author_id' => 1, 'title' => 'A', 'published' => true]);
    $now = CarbonImmutable::now();
    foreach (range(1, 5) as $user) {
        DB::table('article_likes')->insert(['user_id' => $user, 'article_id' => 1, 'created_at' => $now->subHours(2)->format('Y-m-d H:i:s')]);
    }
    app(ScoreUpdater::class)->run($feed, $now);
    app(ScoreUpdater::class)->run($feed, $now->addMinutes(10));
    app(ScoreUpdater::class)->run($feed, $now->addMinutes(20));
    DB::table('article_likes')->insert(['user_id' => 9, 'article_id' => 1, 'created_at' => $now->addMinutes(25)->format('Y-m-d H:i:s')]);
    app(ScoreUpdater::class)->run($feed, $now->addMinutes(30));

    expect((int) DB::table('rec_item_scores')->where('item_id', '1')->value('positive'))->toBe(6);
})->with(['UTC', 'Asia/Hong_Kong', 'America/New_York']);
