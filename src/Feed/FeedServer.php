<?php

namespace SolutionForest\Recommender\Feed;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SolutionForest\Recommender\Eloquent\CatalogTail;
use SolutionForest\Recommender\Eloquent\ModelCards;
use SolutionForest\Recommender\Ranking\Ranker;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\FeedPage;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Support\Report;
use Throwable;

/**
 * Serves one page of a feed. The hot path: cache reads, plus one
 * primary-key query for cards that are not cached yet.
 *
 * Signed-in: the user's ranked list → trending → the catalog tail, skipping
 * everything already served (the seen list). Guests and switched-off feeds:
 * the shared trending list → the catalog tail, by offset.
 */
final class FeedServer
{
    public function __construct(
        private readonly FeedCache $cache,
        private readonly ListBuilder $lists,
        private readonly Ranker $ranker,
        private readonly Bindings $bindings,
        private readonly ImpressionRecorder $impressions,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function personal(FeedDefinition $feed, int|string $userId, int $page, int $perPage, array $attributes): FeedPage
    {
        $listVariant = Variants::list($feed, $attributes);
        $seenVariant = Variants::seen($feed, $attributes);
        $seen = $this->cache->seen($feed, $userId, $seenVariant);
        $seenSet = array_flip($seen);
        $context = new Context($feed, $userId, [], $attributes, CarbonImmutable::now());
        $origin = 'personal';

        // 1. The cached ranked list, rebuilt when stale or (on page 1) nearly used up.
        $list = $this->cache->list($feed, $userId, $listVariant);
        $ids = $list['ids'] ?? [];
        $age = $list === null ? PHP_INT_MAX : time() - $list['built_at'];
        $unseen = self::without($ids, $seenSet);
        $stale = $list === null
            || $age > $feed->int('feed_ttl', 1800)
            || ($page === 1 && count($unseen) < $perPage && $age > $feed->int('min_rebuild_interval', 60));

        if ($stale && ($built = $this->lists->personalWithLock($feed, $userId, $seen, $attributes)) !== null) {
            $ids = $built;
            $unseen = self::without($built, $seenSet);
        }

        // 2. Nearly used up: continue with trending (re-filtered for this viewer).
        if (count($unseen) < $perPage + 1) {
            $unseen = $this->ranker->appendAllowed($context, $unseen, $this->lists->trending($feed, $attributes), $seenSet);
            $origin = $ids === [] ? 'trending' : $origin;
        }

        // 3. Still short: continue with the newest items of the catalog.
        $tail = [];
        if (count($unseen) < $perPage + 1) {
            [$unseen, $tail] = $this->appendTail($context, $unseen, $seenSet, $perPage + 1 - count($unseen));
        }

        // 4. Never show an empty first screen: allow previously seen items.
        if ($page === 1 && count($unseen) < $perPage) {
            $unseen = $this->ranker->appendAllowed($context, $unseen, array_merge($ids, $this->lists->trending($feed, $attributes)), limit: $perPage);
        }

        [$cards, $servedIds, $consumed] = $this->render($feed, $unseen, $perPage, $attributes);
        $firstShown = $this->markSeen($feed, $userId, $consumed, $seenVariant);
        $this->impressions->record($feed, array_values(array_filter($servedIds, fn ($id) => isset($firstShown[(string) $id]))));
        $this->hintIfEmpty($feed, $page, $servedIds);

        if ($servedIds !== [] && $tail !== [] && array_diff(array_map('strval', $servedIds), array_keys($tail)) === []) {
            $origin = 'tail';
        }

        return $this->page($feed, $cards, $servedIds, $page, $perPage, max(0, count($unseen) - count($consumed)), $origin, sequential: true);
    }

    /**
     * Guests and switched-off feeds: the shared trending list, then the
     * catalog tail, paged by offset.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function shared(FeedDefinition $feed, int $page, int $perPage, array $attributes, bool $countImpressions = true): FeedPage
    {
        $ids = $this->lists->trending($feed, $attributes);
        $offset = ($page - 1) * $perPage;
        $window = array_slice($ids, $offset);

        $origin = 'trending';
        if (count($window) < $perPage + 1 && ($tail = $this->tail($feed)) !== null) {
            $context = new Context($feed, null, [], $attributes, CarbonImmutable::now());
            $more = $tail->ids($context, $ids, $perPage + 1 - count($window), max(0, $offset - count($ids)));
            $origin = $window === [] && $more !== [] ? 'tail' : $origin;
            $window = array_merge($window, $more);
        }

        [$cards, $servedIds, $consumed] = $this->render($feed, $window, $perPage, $attributes);
        if ($countImpressions) {
            $this->impressions->record($feed, $servedIds);
        }
        $this->hintIfEmpty($feed, $page, $servedIds);

        return $this->page($feed, $cards, $servedIds, $page, $perPage, max(0, count($window) - count($consumed)), $origin);
    }

    /**
     * Take up to $perPage renderable cards from the front of $ids. Items can
     * disappear between build and render (hidden, deleted), so it fetches a
     * little more until the page is full — at most 3 rounds.
     *
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $attributes
     * @return array{0: array<int|string, array<string, mixed>>, 1: list<int|string>, 2: list<int|string>}  cards, their ids, ids consumed (incl. ones that did not render)
     */
    private function render(FeedDefinition $feed, array $ids, int $perPage, array $attributes): array
    {
        $transformer = $this->bindings->cards($feed);
        [$variant, $attributes] = Variants::cards($feed, $attributes);
        if ($transformer instanceof ModelCards) {
            $transformer = $transformer->withAttributes($attributes);
        }

        $cards = [];
        $consumed = [];
        $offset = 0;
        for ($round = 0; $round < 3 && count($cards) < $perPage && $offset < count($ids); $round++) {
            $need = $perPage - count($cards);
            $batch = array_slice($ids, $offset, $need + ($round === 0 ? 0 : $need));
            $offset += count($batch);
            $rendered = $this->cache->cards($feed, $batch, fn (array $missing) => $transformer->cards($missing), $variant);
            foreach ($batch as $id) {
                $consumed[] = $id;
                if (isset($rendered[$id]) && count($cards) < $perPage) {
                    $cards[$id] = $rendered[$id];
                }
            }
        }

        return [$cards, array_map(fn ($id) => Ids::normalize($id), array_keys($cards)), $consumed];
    }

    /**
     * Remember what was served. Returns the ids shown for the first time
     * (they count as impressions). An overloaded cache must not fail the
     * page: at worst some items repeat later and nothing is counted.
     *
     * @param  list<int|string>  $consumed
     * @return array<string, int>
     */
    private function markSeen(FeedDefinition $feed, int|string $userId, array $consumed, ?string $variant): array
    {
        try {
            return array_flip(array_map('strval', $this->cache->markSeen($feed, $userId, $consumed, $variant)));
        } catch (Throwable $e) {
            Report::failure($feed, 'seen-set write', $e);

            return [];
        }
    }

    /**
     * @param  list<int|string>  $queue
     * @param  array<int|string, mixed>  $seenSet
     * @return array{0: list<int|string>, 1: array<string, true>}  queue, tail ids added
     */
    private function appendTail(Context $context, array $queue, array $seenSet, int $need): array
    {
        $tail = $this->tail($context->feed);
        if ($tail === null || $need <= 0) {
            return [$queue, []];
        }

        try {
            $more = $tail->ids($context, array_merge(Ids::normalizeAll(array_keys($seenSet)), $queue), $need);
            $before = count($queue);
            // The model scope already applied visibility; a custom filter class still gets its say.
            $queue = $context->feed->get('filter')
                ? $this->ranker->appendAllowed($context, $queue, $more, $seenSet)
                : array_merge($queue, $more);
        } catch (Throwable $e) {
            Report::failure($context->feed, 'tail', $e);

            return [$queue, []];
        }

        return [$queue, array_fill_keys(array_map('strval', array_slice($queue, $before)), true)];
    }

    /**
     * The feed's catalog tail, or null when it has no model or `tail` is off.
     */
    private function tail(FeedDefinition $feed): ?CatalogTail
    {
        $model = $feed->get('model');
        if (! $feed->get('tail', true) || ! is_string($model) || ! is_a($model, Model::class, true)) {
            return null;
        }
        $order = $feed->get('tail_order');

        return new CatalogTail($model, is_string($order) && $order !== '' ? $order : null);
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function hintIfEmpty(FeedDefinition $feed, int $page, array $ids): void
    {
        // An empty first page is almost always setup (no scores, a filter that
        // hides everything, a missing card). Say where to look — at most hourly.
        if ($page === 1 && $ids === [] && $this->cache->once($feed, 'empty-hint', 3600)) {
            Log::notice("[recommender:{$feed->name}] served an empty first page. Run `php artisan recommender:doctor {$feed->name}` or open /recommender to see why.");
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $cards
     * @param  list<int|string>  $ids
     */
    private function page(FeedDefinition $feed, array $cards, array $ids, int $page, int $perPage, int $remaining, string $origin, bool $sequential = false): FeedPage
    {
        $model = $feed->get('model');

        return new FeedPage(
            items: array_values($cards),
            ids: $ids,
            page: $page,
            perPage: $perPage,
            hasMore: $remaining > 0,
            remaining: $remaining,
            origin: $origin,
            feed: $feed->name,
            model: is_string($model) ? $model : null,
            sequential: $sequential,
        );
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<int|string, mixed>  $seenSet
     * @return list<int|string>
     */
    private static function without(array $ids, array $seenSet): array
    {
        return array_values(array_filter($ids, fn ($id) => ! isset($seenSet[$id])));
    }
}
