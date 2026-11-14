<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SolutionForest\Recommender\Dashboard\Health;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Insights\SlowWatch;
use SolutionForest\Recommender\Tests\Fixtures\StaticSource;

beforeEach(function () {
    StaticSource::$scores = [11 => 3.0, 21 => 2.0, 31 => 1.0];
});

/** A source that takes 30 ms, standing in for a slow relationship query in app code. */
class SlowSourceForTest implements \SolutionForest\Recommender\Contracts\CandidateSource
{
    public function candidates(\SolutionForest\Recommender\Support\Context $context, int $limit): array
    {
        usleep(30_000);

        return [11 => 1.0];
    }
}

function speedLabels(string $feed = 'reviews'): array
{
    return array_column(app(Health::class)->checks(Recommender::feed($feed)), 'label');
}

it('records a slow source and shows it in the checks, but not a fast one', function () {
    $this->configureFeed(['sources' => [StaticSource::class => 1.0, SlowSourceForTest::class => 1.0], 'slow_ms' => 20]);
    Log::spy();

    Recommender::page('reviews', 7);
    Recommender::forgetUser('reviews', 7);
    Recommender::page('reviews', 7);

    $entries = app(SlowWatch::class)->entries(Recommender::feed('reviews'));
    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatchArray(['stage' => 'serving', 'kind' => 'source', 'name' => 'SlowSourceForTest', 'count' => 2])
        ->and($entries[0]['max_ms'])->toBeGreaterThanOrEqual(20.0)
        ->and(implode("\n", speedLabels()))->toContain('Slow source SlowSourceForTest');

    // Logged once an hour, not on every slow call.
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'slow source'))->once();
});

it('records nothing when every part is fast', function () {
    Recommender::page('reviews', 7);

    expect(app(SlowWatch::class)->entries(Recommender::feed('reviews')))->toBe([])
        ->and(implode("\n", speedLabels()))->not->toContain('Slow ');
});

it('names the recommendable scope and the cards of a model feed', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    $example['feeds']['articles']['slow_ms'] = 0; // record every call
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);
    foreach ([1, 2, 3] as $id) {
        Article::create(['id' => $id, 'author_id' => $id, 'title' => "A{$id}", 'published' => true]);
        DB::table('article_likes')->insert(['user_id' => 1, 'article_id' => $id, 'created_at' => now()->subHour()]);
    }
    $this->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();

    Recommender::page('articles', null);
    $names = array_column(app(SlowWatch::class)->entries(Recommender::feed('articles')), 'name');

    expect($names)->toContain('toRecommendationCard (Article)')
        ->and($names)->toContain('recommendable scope (Article)');
});

it('records slow SQL while update-scores runs, and how long the run took', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    $example['feeds']['articles']['slow_query_ms'] = 0; // every query counts as slow here
    $example['feeds']['articles']['slow_scores_seconds'] = 0;
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);
    Article::create(['id' => 1, 'author_id' => 1, 'title' => 'A', 'published' => true]);
    DB::table('article_likes')->insert(['user_id' => 1, 'article_id' => 1, 'created_at' => now()->subHour()]);

    $this->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();

    $feed = Recommender::feed('articles');
    $queries = array_filter(app(SlowWatch::class)->entries($feed), fn ($e) => $e['kind'] === 'query');
    $sql = implode("\n", array_column($queries, 'name'));

    expect($queries)->not->toBeEmpty()
        ->and(array_unique(array_column($queries, 'stage')))->toBe(['scores'])
        ->and($sql)->toContain('article_likes')        // the app's interaction table
        ->and($sql)->not->toContain('rec_state')        // our bookkeeping is left out
        ->and(app(SlowWatch::class)->lastRun($feed, 'update-scores'))->not->toBeNull()
        ->and(implode("\n", speedLabels('articles')))->toContain('Last update-scores run took');

    $levels = array_column(array_filter(app(Health::class)->checks($feed), fn ($c) => str_starts_with($c['label'], 'Last update-scores')), 'level');
    expect($levels)->toBe([Health::WARN]); // over slow_scores_seconds = 0
});

it('does not watch queries outside the jobs', function () {
    $this->configureFeed(['slow_query_ms' => 0]);
    Recommender::page('reviews', 7);
    DB::table('rec_item_scores')->count();

    expect(array_filter(app(SlowWatch::class)->entries(Recommender::feed('reviews')), fn ($e) => $e['kind'] === 'query'))->toBe([]);
});
