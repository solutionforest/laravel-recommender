<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Recommender;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(Recommender::class);

    foreach (range(1, 4) as $i) {
        Article::create(['id' => $i, 'author_id' => 100 + $i, 'title' => "Article {$i}"]);
    }
    $when = now()->subHours(2);
    $likes = [];
    foreach (range(1, 6) as $u) {
        $likes[] = ['user_id' => $u, 'article_id' => 1, 'created_at' => $when];
        $likes[] = ['user_id' => $u, 'article_id' => 2, 'created_at' => $when];
    }
    DB::table('article_likes')->insert($likes);
    DB::table('article_views')->insert(['user_id' => 9, 'article_id' => 4, 'created_at' => $when]);
});

function runJobs(): void
{
    test()->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();
    test()->artisan('recommender:build-neighbours', ['feed' => 'articles'])->assertSuccessful();
}

it('is forbidden outside local without the viewRecommender gate', function () {
    $this->get('/recommender')->assertForbidden();
    $this->actingAs(new GenericUser(['id' => 1]))->get('/recommender')->assertForbidden();
});

it('lets the gate decide who may view it', function () {
    Gate::define('viewRecommender', fn ($user) => $user->id === 42);

    $this->actingAs(new GenericUser(['id' => 1]))->get('/recommender')->assertForbidden();
    $this->actingAs(new GenericUser(['id' => 42]))->get('/recommender')->assertOk();
});

it('is open in the local environment', function () {
    $this->app['env'] = 'local';

    $this->get('/recommender')->assertOk()->assertSee('Recommender');
});

it('shows health, checks and trending for a feed', function () {
    runJobs();
    Gate::define('viewRecommender', fn () => true);

    $this->actingAs(new GenericUser(['id' => 1]))->get('/recommender?feed=articles')
        ->assertOk()
        ->assertSee('articles')
        ->assertSee('Scores up to date')
        ->assertSee('items have neighbours')
        ->assertSee('article_likes → &#039;like&#039;', false)
        ->assertSee('Trending now')
        ->assertSee('id="recommender-live"', false);
});

it('flags pipeline and schema problems before jobs ran', function () {
    config(['recommender.feeds.articles.interactions' => ['article_likes' => 'like', 'article_shares' => 'share', 'article_views' => ['event' => 'view', 'item' => 'post_id']]]);
    $this->app->forgetInstance(Recommender::class);
    Gate::define('viewRecommender', fn () => true);

    $this->actingAs(new GenericUser(['id' => 1]))->get('/recommender')
        ->assertOk()
        ->assertSee('Failing')
        ->assertSee('Scores never ran')
        ->assertSee('Neighbours never built')
        ->assertSee('interactions table [article_shares] does not exist')
        ->assertSee('article_views is missing column(s): post_id');
});

it('explains a user and pauses live refresh while doing so', function () {
    runJobs();
    Gate::define('viewRecommender', fn () => true);

    $this->actingAs(new GenericUser(['id' => 1]))->get('/recommender?feed=articles&user=1')
        ->assertOk()
        ->assertSee('Explain a user')
        ->assertSee('CoInteractionSource')
        ->assertSee('class="stack"', false)
        ->assertDontSee('id="recommender-live"', false);
});

it('runs doctor: non-zero before jobs, success after', function () {
    $this->artisan('recommender:doctor', ['feed' => 'articles'])
        ->expectsOutputToContain('Scores never ran')
        ->assertFailed();

    runJobs();

    $this->artisan('recommender:doctor', ['feed' => 'articles'])
        ->expectsOutputToContain('Feed [articles]')
        ->assertSuccessful();
});

it('warns about events without a weight', function () {
    config(['recommender.feeds.articles.interactions' => ['article_likes' => 'like', 'article_views' => 'bookmark']]);
    $this->app->forgetInstance(Recommender::class);

    $this->artisan('recommender:doctor', ['feed' => 'articles'])
        ->expectsOutputToContain("Event 'bookmark' has no weight");
});
