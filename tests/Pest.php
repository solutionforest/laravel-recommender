<?php

uses(SolutionForest\Recommender\Tests\TestCase::class)->in('Feature');

/**
 * Skip a test in the Redis CI job (RECOMMENDER_TEST_CACHE=redis) when it
 * fakes the cache setup or moves the test clock, which real Redis TTLs ignore.
 */
function skipOnRedis(string $why): void
{
    if (getenv('RECOMMENDER_TEST_CACHE') === 'redis') {
        test()->markTestSkipped("Redis job: {$why}");
    }
}

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
