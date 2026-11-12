<?php

namespace SolutionForest\Recommender\Support;

use DateTimeInterface;

/**
 * One user -> item event read from the application's own tables.
 */
final class Interaction
{
    public function __construct(
        public readonly int|string $userId,
        public readonly int|string $itemId,
        public readonly string $event,
        public readonly DateTimeInterface $occurredAt,
    ) {
    }
}
