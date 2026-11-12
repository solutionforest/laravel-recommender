<?php

namespace SolutionForest\Recommender\Support;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use JsonSerializable;

/**
 * One page of a feed, ready to be returned by a controller.
 *
 * Use {@see toPaginator()} when you want Laravel's usual LengthAwarePaginator
 * (JSON shape, links, Blade `{{ $paginator->links() }}`). Note: page numbers are
 * metadata — uniqueness comes from the seen-set, not SQL OFFSET.
 *
 * Return it straight from a route or controller to get the paginator JSON
 * (plus `origin` / `has_more`), like returning `Model::paginate()`.
 */
final class FeedPage implements JsonSerializable, Responsable
{
    /**
     * @param  list<array<string, mixed>>  $items  cards in display order
     * @param  list<int|string>  $ids  ids of $items in display order
     * @param  string  $origin  'personal' | 'trending' | 'tail'
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
     * `path` defaults to the current URL, as with Eloquent's paginate().
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
            $options + ['path' => Paginator::resolveCurrentPath()],
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
            'next_cursor' => $this->nextCursor(),
        ];
    }

    /**
     * Token for the next page (`?cursor=…`), or null at the end.
     */
    public function nextCursor(): ?string
    {
        return $this->hasMore ? Cursor::encode($this->page + 1) : null;
    }

    /**
     * Cursor-paginator JSON shape (like Laravel's `cursorPaginate()`), for
     * infinite-scroll clients that follow `next_cursor`.
     *
     * @return array<string, mixed>
     */
    public function toCursorArray(?string $path = null): array
    {
        $path ??= Paginator::resolveCurrentPath();
        $next = $this->nextCursor();

        return [
            'data' => $this->items,
            'path' => $path,
            'per_page' => $this->perPage,
            'next_cursor' => $next,
            'next_page_url' => $next === null ? null : $path.'?cursor='.$next,
            'prev_cursor' => null,
            'prev_page_url' => null,
            'origin' => $this->origin,
            'has_more' => $this->hasMore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return Cursor::requested() ? $this->toCursorArray() : $this->toPaginatorArray();
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        if (is_string($request->query('cursor'))) {
            return new JsonResponse($this->toCursorArray($request->url()));
        }

        return new JsonResponse($this->toPaginatorArray([
            'path' => $request->url(),
            'query' => $request->query(),
        ]));
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
