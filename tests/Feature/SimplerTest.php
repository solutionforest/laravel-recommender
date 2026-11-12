<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Recommender as RecommenderService;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(RecommenderService::class);
});

function seedArticles(): void
{
    foreach (range(1, 4) as $i) {
        Article::create(['id' => $i, 'author_id' => 100 + $i, 'title' => "Article {$i}"]);
    }
    $likes = [];
    foreach (range(1, 5) as $u) {
        $likes[] = ['user_id' => $u, 'article_id' => 1, 'created_at' => now()->subHours(2)];
        $likes[] = ['user_id' => $u, 'article_id' => 2, 'created_at' => now()->subHours(2)];
    }
    DB::table('article_likes')->insert($likes);
    test()->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();
}

it('lets the model serve its own feed', function () {
    seedArticles();
    $user = new GenericUser(['id' => 9]);

    $page = Article::recommendedFor($user);

    expect($page)->toBeInstanceOf(\SolutionForest\Recommender\Support\FeedPage::class)
        ->and($page->ids)->toEqual(Recommender::page('articles', new GenericUser(['id' => 10]))->ids);
});

it('asks for a feed name when a model is used by several feeds, and explains when it has none', function () {
    config(['recommender.feeds.articles_b' => config('recommender.feeds.articles')]);
    $this->app->forgetInstance(RecommenderService::class);

    expect(fn () => Article::recommendedFor(null))->toThrow(InvalidArgumentException::class, 'pass the feed name')
        ->and(Article::recommendedFor(null, 'articles_b'))->toBeInstanceOf(\SolutionForest\Recommender\Support\FeedPage::class);

    config(['recommender.feeds' => []]);
    $this->app->forgetInstance(RecommenderService::class);
    expect(fn () => Article::recommendedFor(null))->toThrow(InvalidArgumentException::class, "No recommender feed has 'model'");
});

it('schedules its own jobs with overlap and one-server protection', function () {
    $this->app->forgetInstance(Schedule::class);
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'recommender:'))
        ->mapWithKeys(fn ($e) => [trim(substr($e->command, strpos($e->command, 'recommender:'))) => $e]);

    expect($events->keys()->all())->toEqualCanonicalizing(['recommender:update-scores', 'recommender:build-neighbours', 'recommender:prune'])
        ->and($events['recommender:update-scores']->expression)->toBe('*/10 * * * *')
        ->and($events['recommender:build-neighbours']->withoutOverlapping)->toBeTrue()
        ->and($events['recommender:prune']->onOneServer)->toBeTrue();
});

it('leaves scheduling to the app when disabled', function () {
    config(['recommender.schedule.enabled' => false]);
    $this->app->forgetInstance(Schedule::class);

    expect(collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'recommender:')))->toBeEmpty();
});

it('logs one hint pointing at doctor when a first page is empty', function () {
    Log::spy();

    Recommender::page('articles', null);
    Recommender::page('articles', new GenericUser(['id' => 1]));

    Log::shouldHaveReceived('notice')->once()->withArgs(fn ($message) => str_contains($message, 'recommender:doctor articles'));
});

it('stays quiet when pages have items', function () {
    seedArticles();
    Log::spy();

    Recommender::page('articles', null);

    Log::shouldNotHaveReceived('notice');
});
