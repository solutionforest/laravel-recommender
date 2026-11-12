<?php

namespace SolutionForest\Recommender\Scoring;

/**
 * Re-orders a ranked list so that each page holds at most N items of the
 * same group (e.g. 2 per shop, 1 per writer). Items that would break a cap
 * move to the next page instead of being dropped. If a page cannot be
 * filled under the caps, the caps are relaxed for that page.
 */
final class Diversifier
{
    /**
     * @param  list<int|string>  $ranked
     * @param  array<int|string, array<string, int|string|null>>  $groups  id => [group => key]
     * @param  array<string, int>  $maxPerGroup  group => max items per page
     * @return list<int|string>
     */
    public function apply(array $ranked, array $groups, array $maxPerGroup, int $pageSize): array
    {
        $maxPerGroup = array_filter($maxPerGroup, fn ($max) => $max > 0);
        if ($maxPerGroup === [] || $pageSize <= 0 || count($ranked) <= 1) {
            return $ranked;
        }

        $result = [];
        $queue = $ranked;

        while ($queue !== []) {
            $page = [];
            $deferred = [];
            $counts = [];

            foreach ($queue as $id) {
                if (count($page) >= $pageSize) {
                    $deferred[] = $id;

                    continue;
                }
                if ($this->fits($id, $groups, $maxPerGroup, $counts)) {
                    $page[] = $id;
                    $this->count($id, $groups, $maxPerGroup, $counts);
                } else {
                    $deferred[] = $id;
                }
            }

            if (count($page) < $pageSize && $deferred !== []) {
                // Not enough variety left: relax the caps for this page.
                $page = array_merge($page, array_splice($deferred, 0, $pageSize - count($page)));
            }

            array_push($result, ...$page);
            $queue = $deferred;
        }

        return $result;
    }

    /**
     * @param  array<int|string, array<string, int|string|null>>  $groups
     * @param  array<string, int>  $maxPerGroup
     * @param  array<string, array<int|string, int>>  $counts
     */
    private function fits(int|string $id, array $groups, array $maxPerGroup, array $counts): bool
    {
        foreach ($maxPerGroup as $group => $max) {
            $key = $groups[$id][$group] ?? null;
            if ($key !== null && ($counts[$group][$key] ?? 0) >= $max) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int|string, array<string, int|string|null>>  $groups
     * @param  array<string, int>  $maxPerGroup
     * @param  array<string, array<int|string, int>>  $counts
     */
    private function count(int|string $id, array $groups, array $maxPerGroup, array &$counts): void
    {
        foreach ($maxPerGroup as $group => $max) {
            $key = $groups[$id][$group] ?? null;
            if ($key !== null) {
                $counts[$group][$key] = ($counts[$group][$key] ?? 0) + 1;
            }
        }
    }
}
