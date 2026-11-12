<?php

namespace SolutionForest\Recommender\Tests\Fixtures;

use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Support\Context;

class StaticSource implements CandidateSource
{
    /** @var array<int, float> */
    public static array $scores = [];

    public static int $calls = 0;

    public static bool $throw = false;

    public static function reset(): void
    {
        self::$scores = [];
        self::$calls = 0;
        self::$throw = false;
    }

    public function candidates(Context $context, int $limit): array
    {
        self::$calls++;
        if (self::$throw) {
            throw new \RuntimeException('source down');
        }

        return array_slice(self::$scores, 0, $limit, true);
    }
}
