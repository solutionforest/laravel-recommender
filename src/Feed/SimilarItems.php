<?php

namespace SolutionForest\Recommender\Feed;

use Carbon\CarbonImmutable;
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
 *  - warm: cache reads only; cold: one neighbours row + one card query
 *    (+ one filter query for a custom `filter` class).
 *
 * For feeds with a `model` and no custom `filter`, visibility is applied by
 * the model's `recommendable` scope (as a guest) when cards render, so no
 * separate filter query runs.
 */
final class SimilarItems
{
    public function __construct(
        private readonly NeighbourStore $neighbours,
        private readonly FeedCache $cache,
        private readonly Bindings $bindings,
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
        if ($version === null) {
            return [];
        }

        $variant = Variants::list($feed, $attributes);
        $built = false;
        $ids = $this->cache->similar($feed, $id, $version, $variant, function () use ($feed, $id, $version, $attributes, &$built) {
            $built = true;

            return $this->ids($feed, $id, $version, $attributes);
        });

        // A list just built empty may come from a cached version that a
        // rebuild has since replaced (its rows are gone), e.g. when the
        // builder runs with another cache store than this server. Recheck
        // once: one query, only when building an empty list.
        if ($built && $ids === [] && ($active = $this->neighbours->activeVersion($feed->name)) !== null && $active !== $version) {
            $this->cache->forgetNeighbourVersion($feed);
            $ids = $this->cache->similar($feed, $id, $active, $variant, fn () => $this->ids($feed, $id, $active, $attributes));
        }

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
    private function ids(FeedDefinition $feed, int|string $id, int $version, array $attributes): array
    {
        $ids = [];
        foreach ($this->neighbours->neighboursOf($feed->name, [$id], $version)[$id] ?? [] as [$neighbour]) {
            if ((string) $neighbour !== (string) $id) {
                $ids[] = $neighbour;
            }
        }
        if ($ids === []) {
            return [];
        }

        // Model feeds without a custom filter: the recommendable scope runs
        // (as a guest) when cards render, so a separate filter query would
        // check the same rule twice.
        if (($feed->get('filter') || ! $feed->get('model')) && ($filter = $this->bindings->filter($feed)) !== null) {
            $allowed = array_flip(array_map('strval', $filter->allowed(new Context($feed, null, [], $attributes, CarbonImmutable::now()), $ids)));
            $ids = array_values(array_filter($ids, fn ($candidate) => isset($allowed[(string) $candidate])));
        }

        return Ids::normalizeAll(array_keys($this->cards($feed, $ids, $attributes)));
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

        return $this->cache->cards($feed, $ids, fn (array $missing) => $transformer->cards($missing), $variant);
    }
}
