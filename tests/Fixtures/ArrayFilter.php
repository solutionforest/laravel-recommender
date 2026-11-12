<?php

namespace SolutionForest\Recommender\Tests\Fixtures;

use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Support\Context;

class ArrayFilter implements ItemFilter
{
    /** @var list<int> */
    public static array $hidden = [];

    public static int $calls = 0;

    public static function reset(): void
    {
        self::$hidden = [];
        self::$calls = 0;
    }

    public function allowed(Context $context, array $ids): array
    {
        self::$calls++;

        return array_values(array_filter($ids, fn ($id) => ! in_array($id, self::$hidden, true)));
    }
}
