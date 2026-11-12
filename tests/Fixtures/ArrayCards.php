<?php

namespace SolutionForest\Recommender\Tests\Fixtures;

use SolutionForest\Recommender\Contracts\CardTransformer;

class ArrayCards implements CardTransformer
{
    /** @var list<list<int|string>> */
    public static array $calls = [];

    /** @var list<int> ids that no longer render (deleted / hidden after build) */
    public static array $gone = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$gone = [];
    }

    public function cards(array $ids): array
    {
        self::$calls[] = $ids;
        $out = [];
        foreach ($ids as $id) {
            if (! in_array($id, self::$gone, true)) {
                $out[$id] = ['id' => $id, 'title' => "Review {$id}"];
            }
        }

        return $out;
    }
}
