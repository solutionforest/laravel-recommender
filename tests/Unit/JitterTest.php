<?php

use SolutionForest\Recommender\Scoring\Jitter;

it('keeps the ranking when jitter is zero', function () {
    $scores = [1 => 3.0, 2 => 2.0, 3 => 1.0];

    expect((new Jitter(fn () => 0.99))->apply($scores, 0.0))->toBe($scores);
});

it('orders purely by the random draw when jitter is one', function () {
    $draws = [0.1, 0.9, 0.5];
    $jitter = new Jitter(function () use (&$draws) { return array_shift($draws); });

    // scores 3 > 2 > 1, draws 0.1 / 0.9 / 0.5 -> order 2, 3, 1
    expect(array_keys($jitter->apply([1 => 3.0, 2 => 2.0, 3 => 1.0], 1.0)))->toBe([2, 3, 1]);
});

it('blends score and randomness in between', function () {
    $draws = [0.0, 1.0];
    $jitter = new Jitter(function () use (&$draws) { return array_shift($draws); });

    // j = 0.3: item 1 = 0.7·1.0 + 0.3·0 = 0.70; item 2 = 0.7·0.5 + 0.3·1 = 0.65
    $out = $jitter->apply([1 => 10.0, 2 => 5.0], 0.3);

    expect(array_keys($out))->toBe([1, 2])
        ->and($out[1])->toEqualWithDelta(0.70, 1e-9)
        ->and($out[2])->toEqualWithDelta(0.65, 1e-9);
});

it('clamps jitter to 0..1 and handles empty input', function () {
    expect((new Jitter(fn () => 0.5))->apply([], 1.0))->toBe([])
        ->and(array_keys((new Jitter(fn () => 0.5))->apply([1 => 1.0, 2 => 2.0], -3)))->toBe([1, 2]);
});

it('produces a roughly uniform first item at jitter one', function () {
    mt_srand(42);
    $firsts = [];
    $jitter = new Jitter();
    for ($i = 0; $i < 3000; $i++) {
        $first = array_key_first($jitter->apply([1 => 100.0, 2 => 10.0, 3 => 1.0], 1.0));
        $firsts[$first] = ($firsts[$first] ?? 0) + 1;
    }

    // Each of the 3 items is first about 1/3 of the time, regardless of its score.
    foreach ([1, 2, 3] as $id) {
        expect($firsts[$id])->toBeGreaterThan(850)->toBeLessThan(1150);
    }
});
