<?php

namespace SolutionForest\Recommender\Contracts;

use DateTimeInterface;
use SolutionForest\Recommender\Support\Interaction;

/**
 * Read access to the application's existing interaction data (likes, views,
 * ...). The package never copies raw events into its own tables.
 */
interface InteractionSource
{
    /**
     * Every interaction with $from < occurred_at <= $to, in any order.
     * Should stream (lazy/cursor) rather than load everything at once.
     *
     * @return iterable<Interaction>
     */
    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable;

    /**
     * Item histories of users active since $since, most recent first,
     * at most $perUser items each. Yield userId => list of item ids and
     * stream users in chunks.
     *
     * @return iterable<int|string, list<int|string>>
     */
    public function userHistories(DateTimeInterface $since, int $perUser): iterable;

    /**
     * Most recent distinct items the user interacted with, newest first.
     *
     * @return list<int|string>
     */
    public function recentItems(int|string $userId, int $limit): array;
}
