<?php

use Illuminate\Support\Facades\Cache;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Recommender as RecommenderService;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Tests\Fixtures\ArrayCards;
use SolutionForest\Recommender\Tests\Fixtures\ArrayFilter;
use SolutionForest\Recommender\Tests\Fixtures\StaticSource;

beforeEach(function () {
    // ids in different "shops" (id div 10) so diversity does not interfere
    StaticSource::$scores = [11 => 9.0, 21 => 8.0, 31 => 7.0, 41 => 6.0, 51 => 5.0, 61 => 4.0, 71 => 3.0];
});

function seedTrending(array $ids): void
{
    $deltas = [];
    foreach (array_values($ids) as $i => $id) {
        $deltas[$id] = ['hot' => 100.0 - $i, 'positive' => 0, 'exposure' => 0, 'last' => null];
    }
    app(ScoreStore::class)->merge('reviews', $deltas);
}

it('serves pages in ranked order without repeats', function () {
    $p1 = Recommender::page('reviews', 7, 1);
    $p2 = Recommender::page('reviews', 7, 2);
    $p3 = Recommender::page('reviews', 7, 3);

    expect($p1->ids)->toBe([11, 21, 31])
        ->and($p2->ids)->toBe([41, 51, 61])
        ->and($p3->ids)->toBe([71])
        ->and($p3->hasMore)->toBeFalse()
        ->and($p1->items[0])->toBe(['id' => 11, 'title' => 'Review 11'])
        ->and($p1->origin)->toBe('personal')
        ->and($p1->total())->toBe(7);
});

it('builds once and serves later pages from cache', function () {
    Recommender::page('reviews', 7, 1);
    Recommender::page('reviews', 7, 2);
    Recommender::page('reviews', 7, 3);

    expect(StaticSource::$calls)->toBe(1)
        ->and(ArrayFilter::$calls)->toBe(1);
});

it('reuses cached cards across users', function () {
    Recommender::page('reviews', 7, 1);
    Recommender::page('reviews', 8, 1);

    expect(ArrayCards::$calls)->toHaveCount(1);
});

it('never shows filtered items', function () {
    ArrayFilter::$hidden = [21];

    expect(Recommender::page('reviews', 7, 1)->ids)->toBe([11, 31, 41]);
});

it('skips items that stopped rendering after the build and still fills the page', function () {
    ArrayCards::$gone = [21];

    $page = Recommender::page('reviews', 7, 1);

    expect($page->ids)->toBe([11, 31, 41])->and($page->items)->toHaveCount(3);
});

it('does not repeat items across a page 1 refresh', function () {
    Recommender::page('reviews', 7, 1);
    $this->travel(31)->minutes();

    expect(Recommender::page('reviews', 7, 1)->ids)->toBe([41, 51, 61]);
});

it('limits items of the same group per page', function () {
    $this->configureFeed(['diversity' => ['shop' => 1]]);
    StaticSource::$scores = [11 => 9.0, 12 => 8.5, 13 => 8.0, 21 => 7.0, 31 => 6.0];

    $page = app(RecommenderService::class)->page('reviews', 7, 1);

    expect($page->ids)->toBe([11, 21, 31]);
});

it('tops up with trending when the personal list runs out', function () {
    StaticSource::$scores = [11 => 1.0];
    seedTrending([90, 91, 92]);

    expect(Recommender::page('reviews', 7, 1)->ids)->toBe([11, 90, 91]);
});

it('falls back to trending when every source fails', function () {
    StaticSource::$throw = true;
    seedTrending([90, 91, 92, 93]);

    $page = Recommender::page('reviews', 7, 1);

    expect($page->ids)->toBe([90, 91, 92])->and($page->origin)->toBe('trending');
});

it('serves the shared trending list to guests without touching personal sources', function () {
    seedTrending([90, 91, 92, 93]);

    $p1 = Recommender::page('reviews', null, 1);
    $p2 = Recommender::page('reviews', null, 2);

    expect($p1->ids)->toBe([90, 91, 92])
        ->and($p2->ids)->toBe([93])
        ->and(StaticSource::$calls)->toBe(0);
});

it('serves trending only when the kill switch is off', function () {
    config(['recommender.enabled' => false]);
    seedTrending([90, 91, 92]);

    expect(Recommender::page('reviews', 7, 1)->ids)->toBe([90, 91, 92])
        ->and(StaticSource::$calls)->toBe(0);
});

it('uses the existing list when another request holds the build lock', function () {
    $service = app(RecommenderService::class);
    $service->build('reviews', 7);
    $this->travel(31)->minutes(); // stale
    StaticSource::$scores = [99 => 1.0];

    $lock = Cache::lock('rec:reviews:lock:7', 10);
    $lock->get();
    $this->configureFeed(['build_lock_wait' => 0]);

    $page = app(RecommenderService::class)->page('reviews', 7, 1);
    $lock->release();

    expect($page->ids)->toBe([11, 21, 31]);
});

it('caps the seen set', function () {
    $this->configureFeed(['seen_max' => 4]);
    $service = app(RecommenderService::class);
    $service->page('reviews', 7, 1);
    $service->page('reviews', 7, 2);

    // Stored packed: one short string instead of a serialized array.
    expect(Cache::get('rec:reviews:seen:7'))->toBe('31,41,51,61');
});

it('explains rankings per source', function () {
    $rows = Recommender::explain('reviews', 7, 2);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['id'])->toBe(11)
        ->and($rows[0]['sources'])->toHaveKey('StaticSource');
});

it('rejects unknown feeds', function () {
    Recommender::page('nope', 1);
})->throws(InvalidArgumentException::class);
