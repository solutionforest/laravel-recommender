<?php

uses(SolutionForest\Recommender\Tests\TestCase::class)->in('Feature');

/**
 * Print a measured number for docs/performance.md. Silent unless
 * RECOMMENDER_PERF=1, so the normal run stays quiet.
 */
function perfNote(string $line): void
{
    if (getenv('RECOMMENDER_PERF')) {
        fwrite(STDERR, "[perf] {$line}\n");
    }
}

/**
 * @param  list<float|int>  $values
 */
function percentile(array $values, float $p): float
{
    sort($values);

    return (float) ($values[(int) min(count($values) - 1, max(0, ceil($p / 100 * count($values)) - 1))] ?? 0);
}
