<?php

namespace SolutionForest\Recommender\Support;

/**
 * One page of a feed, ready to be returned by a controller.
 */
final class FeedPage
{
    /**
     * @param  list<array<string, mixed>>  $items  cards in display order
     * @param  list<int|string>  $ids  ids of $items in display order
     * @param  string  $origin  'personal' | 'trending'
     */
    public function __construct(
        public readonly array $items,
        public readonly array $ids,
        public readonly int $page,
        public readonly int $perPage,
        public readonly bool $hasMore,
        public readonly int $remaining,
        public readonly string $origin,
    ) {
    }

    /**
     * Total compatible with length-aware paginators: everything served so
     * far, this page and what is still queued.
     */
    public function total(): int
    {
        return max(0, ($this->page - 1) * $this->perPage) + count($this->items) + $this->remaining;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'has_more' => $this->hasMore,
            'origin' => $this->origin,
        ];
    }
}
