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
 * (links, Blade `{{ $paginator->links() }}`). For a signed-in user the feed is
 * sequential: every request serves the next unseen batch (a refresh advances
 * it), so the JSON is the simple-paginator shape and `next_cursor` is the
 * preferred way to continue. Uniqueness comes from the seen-set, not SQL OFFSET.
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
     * @param  bool  $sequential  signed-in feeds: each request serves the next unseen batch, so there is no real total / last page
     */
    public function __construct(
        public readonly array $items,
        public readonly array $ids,
        public readonly int $page,
        public readonly int $perPage,
        public readonly bool $hasMore,
        public readonly int $remaining,
        public readonly string $origin,
        public readonly ?string $feed = null,
        public readonly ?string $model = null,
        public readonly bool $sequential = false,
    ) {
    }

    /**
     * The items as Eloquent models, in ranked order — for apps that render
     * models themselves (API resources, Blade components) instead of cards.
     * One query. Needs a feed with a `model`.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function models(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->model === null || ! is_a($this->model, \Illuminate\Database\Eloquent\Model::class, true)) {
            throw new \LogicException('FeedPage::models() needs a feed with a model; use $page->ids with a custom cards class.');
        }
        if ($this->ids === []) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $position = array_flip(array_map('strval', $this->ids));

        return $this->model::query()->findMany($this->ids)
            ->sortBy(fn (\Illuminate\Database\Eloquent\Model $m) => $position[(string) $m->getKey()] ?? PHP_INT_MAX)
            ->values();
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
     * `path` and `query` default to the current URL and its query string (so `?sort=` survives paging), as with Eloquent's paginate().
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
            $options + ['path' => Paginator::resolveCurrentPath(), 'query' => Paginator::resolveQueryString()],
        );
    }

    /**
     * Laravel's simple paginator (`simplePaginate()` JSON: no total, no
     * numbered links) — the honest shape for a sequential signed-in feed.
     *
     * @param  array<string, mixed>  $options
     * @return Paginator<int, array<string, mixed>>
     */
    public function toSimplePaginator(array $options = []): Paginator
    {
        return (new Paginator(
            $this->items,
            $this->perPage,
            $this->page,
            $options + ['path' => Paginator::resolveCurrentPath(), 'query' => Paginator::resolveQueryString()],
        ))->hasMorePagesWhen($this->hasMore);
    }

    /**
     * Paginator JSON plus recommender fields (`origin`, `has_more`,
     * `next_cursor`). Signed-in feeds are sequential, so they get the simple
     * paginator shape (`data`, `per_page`, `next_page_url`, no `total` /
     * `last_page` / numbered `links`); guests page by offset and keep the full
     * length-aware shape.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function toPaginatorArray(array $options = []): array
    {
        $base = $this->sequential
            ? array_replace($this->toSimplePaginator($options)->toArray(), ['prev_page_url' => null])
            : $this->toPaginator($options)->toArray();

        return $base + [
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
        return $this->hasMore ? Cursor::encode($this->page + 1, $this->feed) : null;
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
