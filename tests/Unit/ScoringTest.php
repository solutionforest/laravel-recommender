<?php

use Carbon\CarbonImmutable;
use SolutionForest\Recommender\Scoring\Blender;
use SolutionForest\Recommender\Scoring\Diversifier;
use SolutionForest\Recommender\Scoring\Explorer;
use SolutionForest\Recommender\Scoring\Math;

it('normalises each source before weighting', function () {
    $blended = (new Blender())->blend(
        ['a' => [1 => 100.0, 2 => 50.0], 'b' => [2 => 0.2, 3 => 0.1]],
        ['a' => 0.5, 'b' => 0.5],
    );

    // 1 = .5*1, 2 = .5*.5 + .5*1, 3 = .5*.5
    expect(array_keys($blended))->toBe([2, 1, 3])
        ->and($blended[2])->toEqualWithDelta(0.75, 1e-9);
});

it('applies boosters only to existing candidates', function () {
    $blended = (new Blender())->blend(
        ['s' => [1 => 1.0, 2 => 1.0]],
        ['s' => 1.0],
        ['q' => [2 => 1.0, 99 => 1.0]],
        ['q' => 0.5],
    );

    expect(array_keys($blended))->toBe([2, 1])
        ->and($blended)->not->toHaveKey(99);
});

it('keeps first-seen order for equal scores', function () {
    $blended = (new Blender())->blend(['s' => [5 => 1.0, 3 => 1.0, 9 => 1.0]], ['s' => 1.0]);

    expect(array_keys($blended))->toBe([5, 3, 9]);
});

it('interleaves exploration items at the configured ratio', function () {
    $out = (new Explorer())->interleave([1, 2, 3, 4, 5, 6, 7, 8], [100, 101, 2], 0.25);

    expect($out)->toBe([1, 2, 3, 100, 4, 5, 6, 101, 7, 8]);
});

it('does nothing when the exploration ratio is zero', function () {
    expect((new Explorer())->interleave([1, 2], [3], 0))->toBe([1, 2]);
});

it('caps items per group per page and defers the rest', function () {
    $groups = [1 => ['shop' => 'A'], 2 => ['shop' => 'A'], 3 => ['shop' => 'A'], 4 => ['shop' => 'B'], 5 => ['shop' => 'C']];

    $out = (new Diversifier())->apply([1, 2, 3, 4, 5], $groups, ['shop' => 1], 3);

    expect($out)->toBe([1, 4, 5, 2, 3]);
});

it('relaxes caps when a page cannot be filled', function () {
    $groups = [1 => ['shop' => 'A'], 2 => ['shop' => 'A'], 3 => ['shop' => 'A']];

    expect((new Diversifier())->apply([1, 2, 3], $groups, ['shop' => 1], 2))->toBe([1, 2, 3]);
});

it('computes a conservative wilson score', function () {
    expect(Math::wilson(0, 0))->toBe(0.0)
        ->and(Math::wilson(1, 1))->toBeLessThan(Math::wilson(80, 100))
        ->and(Math::wilson(80, 100))->toBeGreaterThan(0.7)->toBeLessThan(0.8);
});

it('ranks recent events above old ones in log space', function () {
    $old = Math::hotTerm(4.0, CarbonImmutable::parse('2026-10-01'), 7);
    $new = Math::hotTerm(1.0, CarbonImmutable::parse('2026-11-01'), 7);

    // A like a month ago (weight 4) is worth less than a view today (weight 1)
    // with a 7 day half-life: 4 * 2^(-31/7) ≈ 0.18.
    expect($new)->toBeGreaterThan($old)
        ->and(Math::logAddExp(null, 1.5))->toBe(1.5)
        ->and(Math::logAddExp(0.0, 0.0))->toEqualWithDelta(log(2), 1e-12);
});
