<?php

namespace SolutionForest\Recommender\Examples\Blog\Support;

use Illuminate\Database\Eloquent\Builder;
use SolutionForest\Recommender\Facades\Recommender;

/**
 * Lab pattern: app-style infinite scroll (2-column masonry, keep loading).
 *
 * 1. Call {@see Recommender::page()} repeatedly — logged-in users advance the
 *    package seen-set each call, so you get the next ranked batch.
 * 2. When {@see FeedPage::$hasMore} is false, switch to a **catalog tail**:
 *    newest remaining visible items excluding ids already shown.
 *
 * The ranked pool alone is *long* (`feed_size` + `trending_size`), not the
 * full catalog. True “scroll until no more posts” needs this tail (or a
 * future package catalog-fallback). Copy into a Livewire/Inertia action.
 */
final class InfiniteScrollFeed
{
    /**
     * @param  list<int|string>  $alreadyShown
     * @param  array<string, mixed>  $attributes  e.g. ['user' => $user]
     * @return array{items: list<array<string, mixed>>, ids: list<int|string>, hasMore: bool, origin: string, phase: 'ranked'|'tail'}
     */
    public function nextRanked(
        string $feed,
        int|string|null $userId,
        int $page,
        array $alreadyShown = [],
        array $attributes = [],
    ): array {
        $result = Recommender::page($feed, $userId, $page, attributes: $attributes);
        $seen = array_flip(array_map('strval', $alreadyShown));
        $items = [];
        $ids = [];

        foreach ($result->items as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $items[] = $item;
            $ids[] = $item['id'];
        }

        return [
            'items' => $items,
            'ids' => $ids,
            'hasMore' => $result->hasMore && $items !== [],
            'origin' => $result->origin,
            'phase' => 'ranked',
        ];
    }

    /**
     * Chronological catalog tail after the ranked pool is exhausted.
     *
     * @param  callable(): Builder<Post>  $query  must already apply visibility; will receive cursor + limit
     * @param  list<int|string>  $alreadyShown
     * @param  callable(Post): (?array<string, mixed>)  $toCard
     * @return array{items: list<array<string, mixed>>, ids: list<int|string>, hasMore: bool, origin: string, phase: 'tail', cursor: ?string}
     */
    public function nextTail(
        callable $query,
        array $alreadyShown,
        callable $toCard,
        ?string $cursor,
        int $limit = 12,
    ): array {
        $fetch = $limit * 4;
        $builder = $query();

        if ($cursor) {
            [$at, $id] = explode('|', $cursor, 2);
            $builder->where(function ($q) use ($at, $id) {
                $q->where('created_at', '<', $at)
                    ->orWhere(function ($q) use ($at, $id) {
                        $q->where('created_at', $at)->where('id', '<', (int) $id);
                    });
            });
        }

        $rows = $builder->orderByDesc('created_at')->orderByDesc('id')->limit($fetch)->get();
        $seen = array_flip(array_map('strval', $alreadyShown));
        $items = [];
        $ids = [];
        $last = null;

        foreach ($rows as $row) {
            $last = $row;
            if (isset($seen[(string) $row->id])) {
                continue;
            }
            $card = $toCard($row);
            if ($card === null) {
                continue;
            }
            $items[] = $card;
            $ids[] = $card['id'] ?? $row->id;
            $seen[(string) $row->id] = true;
            if (count($items) >= $limit) {
                break;
            }
        }

        $nextCursor = $last
            ? ($last->created_at?->format('Y-m-d H:i:s').'|'.$last->id)
            : $cursor;

        $hasMore = $rows->isNotEmpty() && ($rows->count() >= $fetch || count($items) > 0);

        if ($rows->isEmpty() || (count($items) === 0 && $rows->count() < $fetch)) {
            $hasMore = false;
        }

        return [
            'items' => $items,
            'ids' => $ids,
            'hasMore' => $hasMore,
            'origin' => 'tail',
            'phase' => 'tail',
            'cursor' => $nextCursor,
        ];
    }
}
