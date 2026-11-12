<?php

use Illuminate\Http\Request;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Tests\Fixtures\StaticSource;

beforeEach(function () {
    StaticSource::reset();
    StaticSource::$scores = [11 => 9.0, 21 => 8.0, 31 => 7.0, 41 => 6.0];
});

it('does not cache a failed trending build', function () {
    $this->configureFeed(['trending_sources' => [StaticSource::class => 1.0]]);

    StaticSource::$throw = true;
    expect(Recommender::trending('reviews'))->toBe([]);

    // The source recovers: the empty list is held for 15 seconds at most, not for trending_ttl (10 min).
    StaticSource::$throw = false;
    $this->travel(16)->seconds();
    expect(Recommender::trending('reviews'))->toBe([11, 21, 31, 41]);
});

it('still caches a successful trending build', function () {
    $this->configureFeed(['trending_sources' => [StaticSource::class => 1.0]]);

    Recommender::trending('reviews');
    Recommender::trending('reviews');

    expect(StaticSource::$calls)->toBe(1);
});

it('keeps the request query string on every paginator path', function () {
    $this->app->instance('request', Request::create('/feed?sort=new&page=1'));
    Illuminate\Pagination\Paginator::queryStringResolver(fn () => request()->query());

    $this->configureFeed(['trending_sources' => [StaticSource::class => 1.0]]);
    $page = Recommender::page('reviews', null, 1);
    $json = json_decode(json_encode($page), true);

    expect($json['next_page_url'])->toContain('sort=new')
        ->and($page->toPaginator()->url(2))->toContain('sort=new');
});

it('defaults seen_max to the documented 200', function () {
    $feed = Recommender::feed('reviews');

    expect($feed->int('seen_max', 200))->toBe(200)
        ->and((require __DIR__.'/../../config/recommender.php')['defaults']['seen_max'])->toBe(200);
});
