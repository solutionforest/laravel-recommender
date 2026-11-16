<?php

namespace SolutionForest\Recommender\Feed;

use Carbon\CarbonImmutable;
use SolutionForest\Recommender\Content\AttributeMatcher;
use SolutionForest\Recommender\Insights\SlowWatch;
use SolutionForest\Recommender\Eloquent\ModelCards;
use SolutionForest\Recommender\Storage\NeighbourStore;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Ids;

/**
 * "Similar items" for one item, e.g. a related-posts block: the neighbours
 * build-neighbours already computes (people who liked this also liked …),
 * filtered and rendered as cards.
 *
 * Deliberately viewer-independent, so it is cached per item and its cost
 * does not grow with users:
 *
 *  - nothing new is stored: it reads the one rec_item_neighbours row of the item;
 *  - the list of showable ids is cached per item and neighbour version, so
 *    the nightly rebuild switches it over on its own;
 *  - warm: cache reads only; cold: one neighbours row + one filter query
 *    (the `recommendable` scope or a custom `filter`, as a guest) + one card
 *    query for cards not cached yet.
 *
 * The filter always runs on a cold build. Cards come from the shared card
 * cache, so rendering them cannot be relied on to apply the scope: a card
 * cached for one `list_vary_by` value (e.g. another tenant) would otherwise
 * pass for every value.
 *
 * With a `match` config, an item without neighbours (new, or too few
 * shared readers) falls back to items with the same attributes
 * (AttributeMatcher): a bounded pool, cached the same way.
 */
final class SimilarItems
{
    public function __construct(
        private readonly NeighbourStore $neighbours,
        private readonly FeedCache $cache,
        private readonly Bindings $bindings,
        private readonly SlowWatch $watch,
        private readonly AttributeMatcher $matcher,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes  for `list_vary_by` filters and `cards_vary_by` (e.g. locale); there is no viewer
     * @return list<array<string, mixed>> cards, most similar first; empty until the item has neighbours
     */
    public function for(FeedDefinition $feed, int|string $id, int $limit, array $attributes = []): array
    {
        $limit = min(max(1, $limit), max(1, $feed->int('neighbours_max', 30)));
        $id = Ids::normalize($id);

        $version = $this->cache->neighbourVersion($feed, fn () => $this->neighbours->activeVersion($feed->name));
        $fallback = $this->matcher->configured($feed);
        if ($version === null && ! $fallback) {
            return [];
        }

        $ids = $this->cache->similar(
            $feed,
            $id,
            $version ?? 0, // never built: only the attribute fallback can answer
            Variants::list($feed, $attributes),
            fn () => $this->ids($feed, $id, $version, $fallback, $attributes),
        );

        return $this->render($feed, $ids, $limit, $attributes);
    }

    /**
     * Neighbour ids of $id, best first, that may be shown: not the item
     * itself, allowed by the feed's filter and able to render a card. Cards
     * are rendered here (one query, then cached), so hidden items never
     * reach the cached list and warm reads need no query at all.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>
     */
    private function ids(FeedDefinition $feed, int|string $id, ?int $version, bool $fallback, array $attributes): array
    {
        $ids = $version === null ? [] : $this->neighbourIds($feed, $id, $version);

        // Empty may also mean a cached version that a rebuild has since
        // replaced (its rows are gone), e.g. when the builder runs with
        // another cache store than this server. Recheck once: one query,
        // only when building an empty list.
        if ($ids === [] && $version !== null && ($active = $this->neighbours->activeVersion($feed->name)) !== null && $active !== $version) {
            $this->cache->forgetNeighbourVersion($feed);
            $ids = $this->neighbourIds($feed, $id, $active);
        }

        $context = new Context($feed, null, [], $attributes, CarbonImmutable::now());
        if ($ids === [] && $fallback) {
            $ids = array_keys($this->watch->measure($feed, 'source', 'match', fn () => $this->matcher->scores($feed, [$id], $context, $feed->int('neighbours_max', 30))));
        }
        if ($ids === []) {
            return [];
        }

        // Always filter here, also with the default ModelFilter: cards come
        // from the shared card cache, so the scope does not run for an item
        // whose card another variant (e.g. tenant) has already cached.
        if (($filter = $this->bindings->filter($feed)) !== null) {
            $allowed = array_flip(array_map('strval', $this->watch->measure($feed, 'filter', SlowWatch::name($feed, $filter), fn () => $filter->allowed($context, $ids))));
            $ids = array_values(array_filter($ids, fn ($candidate) => isset($allowed[(string) $candidate])));
        }

        return Ids::normalizeAll(array_keys($this->cards($feed, $ids, $attributes)));
    }

    /**
     * @return list<int|string>
     */
    private function neighbourIds(FeedDefinition $feed, int|string $id, int $version): array
    {
        $ids = [];
        foreach ($this->neighbours->neighboursOf($feed->name, [$id], $version)[$id] ?? [] as [$neighbour]) {
            if ((string) $neighbour !== (string) $id) {
                $ids[] = $neighbour;
            }
        }

        return $ids;
    }

    /**
     * Up to $limit cards from the front of $ids. Items hidden since the list
     * was cached do not render, so a second round fills from the rest.
     *
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    private function render(FeedDefinition $feed, array $ids, int $limit, array $attributes): array
    {
        $cards = [];
        $offset = 0;
        for ($round = 0; $round < 2 && count($cards) < $limit && $offset < count($ids); $round++) {
            $batch = array_slice($ids, $offset, $round === 0 ? $limit : null);
            $offset += count($batch);
            foreach ($this->cards($feed, $batch, $attributes) as $card) {
                if (count($cards) < $limit) {
                    $cards[] = $card;
                }
            }
        }

        return $cards;
    }

    /**
     * Cards for $ids from the card cache (rendering misses in one query),
     * keyed by id, in order; ids that do not render are left out.
     *
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $attributes
     * @return array<int|string, array<string, mixed>>
     */
    private function cards(FeedDefinition $feed, array $ids, array $attributes): array
    {
        if ($ids === []) {
            return [];
        }

        $transformer = $this->bindings->cards($feed);
        [$variant, $attributes] = Variants::cards($feed, $attributes);
        if ($transformer instanceof ModelCards) {
            $transformer = $transformer->withAttributes($attributes);
        }

        return $this->cache->cards($feed, $ids, fn (array $missing) => $this->watch->measure($feed, 'cards', SlowWatch::name($feed, $transformer), fn () => $transformer->cards($missing)), $variant);
    }
}
