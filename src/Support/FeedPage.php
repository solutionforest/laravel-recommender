<?php

namespace SolutionForest\Recommender\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One page of a feed, ready to be returned by a controller.
 *
 * Use {@see toPaginator()} when you want Laravel's usual LengthAwarePaginator
 * (JSON shape, links, Blade `{{ $paginator->links() }}`). Note: page numbers are
 * metadata — uniqueness comes from the seen-set, not SQL OFFSET.
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
     * Laravel LengthAwarePaginator for API JSON / Blade pagination links.
     *
     * @param  array<string, mixed>  $options  e.g. ['path' => $request->url(), 'query' => $request->query()]
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function toPaginator(array $options = []): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $this->items,
            $this->total(),
            $this->perPage,
            $this->page,
            $options,
        );
    }

    /**
     * Paginator JSON shape plus recommender fields (`origin`, `has_more`).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function toPaginatorArray(array $options = []): array
    {
        return $this->toPaginator($options)->toArray() + [
            'origin' => $this->origin,
            'has_more' => $this->hasMore,
        ];
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
