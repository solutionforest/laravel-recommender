<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Recommender\Dashboard\Health;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Insights\SpeedProbe;

function simpleFeedForProbe(array $extra = []): void
{
    test()->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    $example['feeds']['articles'] = $extra + $example['feeds']['articles'];
    config(['recommender.feeds' => $example['feeds']]);
    app()->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    foreach ([1, 2, 3, 4] as $id) {
        Article::create(['id' => $id, 'author_id' => $id, 'title' => "A{$id}", 'published' => true]);
    }
    foreach ([[7, 1], [7, 2], [8, 2], [8, 3]] as [$user, $article]) {
        DB::table('article_likes')->insert(['user_id' => $user, 'article_id' => $article, 'created_at' => now()->subMinutes(20)]);
    }
}

it('warns before anything runs when an interaction table lacks the indexes the jobs need', function () {
    simpleFeedForProbe();
    Schema::create('article_saves', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');      // no index
        $table->unsignedBigInteger('article_id');
        $table->timestamp('created_at')->nullable(); // no index
    });
    config(['recommender.feeds.articles.interactions.article_saves' => 'save', 'recommender.feeds.articles.events.save' => 3.0]);
    app()->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    $labels = array_column(app(Health::class)->checks(Recommender::feed('articles')), 'label');

    expect($labels)->toContain('article_saves has no index on created_at')
        ->and($labels)->toContain('article_saves has no index starting with user_id')
        ->and(implode("\n", $labels))->not->toContain('article_likes has no index'); // the example tables are indexed
})->skip(fn () => ! method_exists(Schema::getFacadeRoot(), 'getIndexes'), 'getIndexes() needs Laravel 10.35+');

it('probes every part once on real data, read-only', function () {
    simpleFeedForProbe();
    $before = [DB::table('rec_item_scores')->count(), DB::table('rec_state')->count()];

    $rows = app(SpeedProbe::class)->run(Recommender::feed('articles'));
    $parts = array_column($rows, 'part');

    expect($parts)->toContain('update-scores: read the last hour')
        ->and($parts)->toContain('build-neighbours: read the first chunk of users')
        ->and(implode("\n", $parts))->toContain('recent items of user')
        ->and(implode("\n", $parts))->toContain('personal list for user')
        ->and($parts)->toContain('guest list')
        ->and(implode("\n", $parts))->toContain('visibility check (recommendable scope / filter) for 4 items')
        ->and($parts)->toContain('  filter recommendable scope (Article)')
        ->and($parts)->toContain('cards for one page')
        ->and($parts)->toContain('catalog tail (one page)')
        ->and(array_column($rows, 'slow'))->not->toContain(true)
        ->and($rows[0]['detail'])->toBe('4 events')
        ->and([DB::table('rec_item_scores')->count(), DB::table('rec_state')->count()])->toBe($before); // wrote nothing
});

it('flags a part that is slower than its limit', function () {
    simpleFeedForProbe(['slow_ms' => 0, 'slow_query_ms' => 0]);

    $rows = app(SpeedProbe::class)->run(Recommender::feed('articles'));

    // Both interaction tables answer in ~0 ms, so either can be the slowest one.
    expect(array_unique(array_column($rows, 'slow')))->toBe([true])
        ->and($rows[0]['detail'])->toContain('slowest SQL')
        ->and($rows[0]['detail'])->toMatch('/from "?article_(likes|views)"?/');
});

it('prints the probe in doctor --probe', function () {
    simpleFeedForProbe();
    $this->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'articles'])->assertSuccessful();

    $this->withoutMockingConsoleOutput();
    Artisan::call('recommender:doctor', ['feed' => 'articles', '--probe' => true]);
    $out = Artisan::output();

    expect($out)->toContain('Speed probe')
        ->and($out)->toContain('update-scores: read the last hour')
        ->and($out)->toContain('guest list');
});
