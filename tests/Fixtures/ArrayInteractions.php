<?php

namespace SolutionForest\Recommender\Tests\Fixtures;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use SolutionForest\Recommender\Contracts\InteractionSource;
use SolutionForest\Recommender\Support\Interaction;

class ArrayInteractions implements InteractionSource
{
    /** @var list<Interaction> */
    public static array $events = [];

    public static int $betweenCalls = 0;

    /** Throw on the N-th between() call (simulates a crash mid-run). */
    public static ?int $failOnCall = null;

    public static function reset(): void
    {
        self::$events = [];
        self::$betweenCalls = 0;
        self::$failOnCall = null;
    }

    public static function add(int $user, int $item, string $event, string $at): void
    {
        self::$events[] = new Interaction($user, $item, $event, CarbonImmutable::parse($at));
    }

    public function between(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        self::$betweenCalls++;
        if (self::$failOnCall !== null && self::$betweenCalls === self::$failOnCall) {
            throw new \RuntimeException('database went away');
        }
        foreach (self::$events as $e) {
            if ($e->occurredAt > $from && $e->occurredAt <= $to) {
                yield $e;
            }
        }
    }

    public function userHistories(DateTimeInterface $since, int $perUser): iterable
    {
        $byUser = [];
        foreach (self::$events as $e) {
            if ($e->occurredAt >= $since) {
                $byUser[$e->userId][] = $e;
            }
        }
        foreach ($byUser as $user => $events) {
            usort($events, fn ($a, $b) => $b->occurredAt <=> $a->occurredAt);
            yield $user => array_slice(array_values(array_unique(array_map(fn ($e) => $e->itemId, $events))), 0, $perUser);
        }
    }

    public function recentItems(int|string $userId, int $limit): array
    {
        $events = array_filter(self::$events, fn ($e) => $e->userId === $userId);
        usort($events, fn ($a, $b) => $b->occurredAt <=> $a->occurredAt);

        return array_slice(array_values(array_unique(array_map(fn ($e) => $e->itemId, $events))), 0, $limit);
    }
}
