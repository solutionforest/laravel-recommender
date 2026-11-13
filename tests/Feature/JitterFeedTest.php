<?php

use SolutionForest\Recommender\Recommender as RecommenderService;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Tests\Fixtures\StaticSource;

beforeEach(function () {
    // 30 candidates in different "shops" (id div 10), strictly descending scores.
    StaticSource::$scores = [];
    foreach (range(1, 30) as $i) {
        StaticSource::$scores[$i * 10 + 1] = 100.0 - $i;
    }
});

function servedIds(int $user, int $pages): array
{
    $ids = [];
    for ($p = 1; $p <= $pages; $p++) {
        array_push($ids, ...app(RecommenderService::class)->page('reviews', $user, $p)->ids);
    }

    return $ids;
}

it('keeps the ranked order by default', function () {
    expect(servedIds(7, 2))->toBe([11, 21, 31, 41, 51, 61]);
});

it('serves every candidate once, in a shuffled order, at jitter one', function () {
    $this->configureFeed(['jitter' => 1.0]);
    mt_srand(7);

    $ids = servedIds(7, 10); // page_size 3 -> 30 items

    $ranked = array_keys(StaticSource::$scores);
    expect($ids)->toHaveCount(30)
        ->and(array_unique($ids))->toHaveCount(30)       // no repeats across pages
        ->and(collect($ids)->sort()->values()->all())->toBe(collect($ranked)->sort()->values()->all())
        ->and($ids)->not->toBe($ranked);                 // not the ranked order
});

it('gives different users different orders but keeps each order while cached', function () {
    $this->configureFeed(['jitter' => 1.0]);
    mt_srand(11);

    $a = app(RecommenderService::class)->page('reviews', 7, 1)->ids;
    $b = app(RecommenderService::class)->page('reviews', 8, 1)->ids;
    $a2 = app(RecommenderService::class)->page('reviews', 7, 2)->ids;

    expect($a)->not->toBe($b)
        ->and(array_intersect($a, $a2))->toBe([]); // page 2 continues the same cached shuffle
});

it('never shuffles the shared trending list', function () {
    $this->configureFeed(['jitter' => 1.0]);
    $deltas = [];
    foreach ([90, 91, 92, 93] as $i => $id) {
        $deltas[$id] = ['hot' => 100.0 - $i, 'positive' => 0, 'exposure' => 0, 'last' => null];
    }
    app(ScoreStore::class)->merge('reviews', $deltas);

    expect(app(RecommenderService::class)->page('reviews', null, 1)->ids)->toBe([90, 91, 92]);
});
