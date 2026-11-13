<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Recommender as RecommenderService;

/*
 * Laravel Octane keeps one booted app per worker and serves each request on
 * a clone of it. This mirrors Worker::handle(): clone → forget scoped
 * instances → set as current app (+ facades) → handle → terminate → flush.
 */
function octaneRequest($base, callable $handle): mixed
{
    $sandbox = clone $base;
    $sandbox->forgetScopedInstances();
    (fn () => $this->isRunningInConsole = false)->call($sandbox); // bin/bootstrap.php sets APP_RUNNING_IN_CONSOLE=false
    $sandbox->instance('app', $sandbox);
    $sandbox->instance(Container::class, $sandbox);
    Container::setInstance($sandbox);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($sandbox);
    $sandbox->instance('config', clone $base['config']); // Octane gives each request its own config copy
    $out = $handle($sandbox);
    $sandbox->terminate();
    $sandbox->flush();
    Container::setInstance($base);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($base);

    return $out;
}

it('serves correctly in a long-lived worker like Octane', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    config(['recommender.feeds' => $example['feeds'], 'recommender.feeds.articles.impressions' => true]);
    $this->app->forgetInstance(RecommenderService::class);
    foreach (range(1, 30) as $i) Article::create(['id' => $i, 'author_id' => 100 + $i, 'title' => "A{$i}"]);
    $likes = [];
    foreach (range(1, 5) as $u) foreach ([1, 2, 3] as $a) $likes[] = ['user_id' => $u, 'article_id' => $a, 'created_at' => now()->subHour()];
    DB::table('article_likes')->insert($likes);
    $this->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();
    app(RecommenderService::class); // resolved in the worker's base app, as after boot or a warm
    $base = $this->app;

    // Same user, two requests: page 2 continues without repeats; impressions written on terminate.
    $p1 = octaneRequest($base, fn () => Recommender::page('articles', new GenericUser(['id' => 9]), 1));
    $p2 = octaneRequest($base, fn () => Recommender::page('articles', new GenericUser(['id' => 9]), 2));
    expect(array_intersect($p1->ids, $p2->ids))->toBe([])
        ->and((int) DB::table('rec_item_scores')->sum('impressions'))->toBe(count($p1->ids) + count($p2->ids));

    // Per-request config: switching the feed off in one request does not leak into the next.
    $off = octaneRequest($base, function ($app) { $app['config']->set('recommender.enabled', false); return Recommender::enabled('articles'); });
    $on = octaneRequest($base, fn () => Recommender::enabled('articles'));
    // A per-request feed config change (e.g. multi-tenant page_size) is seen by that request.
    $size = octaneRequest($base, function ($app) { $app['config']->set('recommender.feeds.articles.page_size', 3); return count(Recommender::page('articles', null, 1)->ids); });
    $sizeNext = octaneRequest($base, fn () => count(Recommender::page('articles', null, 1)->ids));
    expect($off)->toBeFalse()->and($on)->toBeTrue()
        ->and($size)->toBe(3)->and($sizeNext)->toBe(12);
});
