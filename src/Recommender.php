<?php

namespace SolutionForest\Recommender;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;
use LogicException;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Feed\FeedRegistry;
use SolutionForest\Recommender\Feed\FeedServer;
use SolutionForest\Recommender\Feed\ListBuilder;
use SolutionForest\Recommender\Feed\SimilarItems;
use SolutionForest\Recommender\Feed\Variants;
use SolutionForest\Recommender\Insights\ItemStats;
use SolutionForest\Recommender\Interactions\DatabaseInteractions;
use SolutionForest\Recommender\Jobs\BuildFeed;
use SolutionForest\Recommender\Ranking\Ranker;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Cursor;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\FeedPage;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Support\Value;
use SolutionForest\Recommender\Support\Viewer;
use Carbon\CarbonImmutable;

/**
 * The public face of the package (also the `Recommender` facade). Each
 * method resolves the feed and the viewer, then hands off:
 *
 *  - FeedRegistry   config → FeedDefinition (defaults < preset < feed)
 *  - FeedServer     serve a page (personal or shared)
 *  - ListBuilder    build + cache ranked lists
 *  - Ranker         sources → filter → blend → boosters → exploration → diversity
 *  - ItemStats      "how good is this item?"
 *  - SimilarItems   "more like this item" (neighbours as cards)
 */
class Recommender
{
    private readonly FeedRegistry $registry;

    public function __construct(
        private readonly FeedCache $cache,
        private readonly Bindings $bindings,
        private readonly Ranker $ranker,
        private readonly ListBuilder $lists,
        private readonly FeedServer $server,
        private readonly ItemStats $itemStats,
        private readonly SimilarItems $similarItems,
    ) {
        $this->registry = new FeedRegistry(); // per instance: forgetInstance() re-reads config
    }

    public function feed(string $name): FeedDefinition
    {
        return $this->registry->get($name);
    }

    /**
     * The one feed whose `model` is $model.
     *
     * @param  class-string|Model  $model
     */
    public function feedFor(string|Model $model): string
    {
        return $this->registry->nameFor($model);
    }

    /**
     * Package presets, overridden / extended by the app's `presets`.
     *
     * @return array<string, mixed>
     */
    public function presets(): array
    {
        return $this->registry->presets();
    }

    /**
     * @return list<string>
     */
    public function feeds(): array
    {
        return $this->registry->names();
    }

    /**
     * Is the package (and, when given, this feed) switched on?
     *
     * Off = everyone gets the shared trending list from the last computed
     * scores; nothing personal is built, no seen lists or impressions are
     * written, warm() does nothing and the scheduled jobs pause. Global:
     * RECOMMENDER_ENABLED=false. One feed: 'enabled' => false on the feed.
     */
    public function enabled(?string $feed = null): bool
    {
        return $this->registry->enabled($feed);
    }

    /**
     * Serve one page. This is the hot path: cache reads only, plus one
     * primary-key query for cards that are not cached yet.
     *
     * Page 1 reuses the user's list while it is fresh and still has unseen
     * items; otherwise it rebuilds. Later pages continue from the same list,
     * skipping everything already served (the seen-set), and top up with the
     * trending list when the personal list runs out.
     *
     * Pass the user model (or its id, or null for guests). The page number
     * defaults to the request's `?page=` like Eloquent's paginate().
     *
     * @param  Authenticatable|int|string|null  $user  the viewer; a model is also available to sources as `$context->attribute('user')`
     * @param  array<string, mixed>  $attributes  passed to sources/filters via Context
     */
    public function page(string $feedName, Authenticatable|int|string|null $user = null, ?int $page = null, ?int $perPage = null, array $attributes = []): FeedPage
    {
        [$userId, $attributes] = Viewer::resolve($user, $attributes);
        $feed = $this->feed($feedName);
        $page = max(1, $page ?? Cursor::currentPage($feedName) ?? Paginator::resolveCurrentPage());
        $perPage = max(1, $perPage ?? $feed->int('page_size', 12));
        $enabled = $this->enabled($feedName);

        return $userId === null || ! $enabled
            ? $this->server->shared($feed, $page, $perPage, $attributes, countImpressions: $enabled)
            : $this->server->personal($feed, $userId, $page, $perPage, $attributes);
    }

    /**
     * Build and store the ranked list for one user. Safe to call from a job.
     *
     * @param  list<int|string>|null  $seen
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>
     */
    public function build(string $feedName, Authenticatable|int|string $user, ?array $seen = null, array $attributes = []): array
    {
        [$userId, $attributes] = Viewer::resolve($user, $attributes);
        $userId ??= throw new InvalidArgumentException('Cannot build a feed for a user without an id.');
        $feed = $this->feed($feedName);

        return $this->lists->personal($feed, $userId, $seen ?? $this->cache->seen($feed, $userId, Variants::seen($feed, $attributes)), $attributes);
    }

    /**
     * Shared list used for guests, the kill switch and as a fallback.
     * Built at most once per trending_ttl for everyone.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<int|string>
     */
    public function trending(string $feedName, array $attributes = []): array
    {
        return $this->lists->trending($this->feed($feedName), $attributes);
    }

    /**
     * Queue a background rebuild (e.g. on login / app open). Pass the
     * attributes listed in `list_vary_by` to warm that variant of the list.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function warm(string $feedName, Authenticatable|int|string $user, array $attributes = []): void
    {
        $this->feed($feedName);
        if ($this->enabled($feedName)) { // switched off: nothing to warm
            BuildFeed::dispatch($feedName, Viewer::id($user, 'warm a feed'), $attributes);
        }
    }

    /**
     * The cache variant of a list for these attributes (`list_vary_by`), or
     * null when the feed does not vary its lists.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function listVariant(FeedDefinition|string $feed, array $attributes): ?string
    {
        return Variants::list($feed instanceof FeedDefinition ? $feed : $this->feed($feed), $attributes);
    }

    /**
     * Record an interaction in the feed's own table, with its configured
     * columns, e.g. `recordInteraction('posts', $post, $user, 'like', once: true)`.
     * Plain insert; `once: true` makes it idempotent (needs a unique index on
     * user + item) and returns false when it already existed. Unliking is
     * your own delete: scores are decayed counters and do not go down.
     * Fires no events and dispatches no jobs; scores update on the schedule.
     *
     * @param  ?string  $table  only when two tables share the event name
     * @return bool true when a row was added
     */
    public function recordInteraction(string $feedName, Model|int|string $item, Authenticatable|int|string $user, string $event, bool $once = false, ?string $table = null, ?\DateTimeInterface $at = null): bool
    {
        $source = $this->bindings->interactions($this->feed($feedName));
        if (! $source instanceof DatabaseInteractions) {
            throw new LogicException("Feed [{$feedName}] reads interactions from a class; record them where that class reads them, or switch to a table map ('interactions' => ['table' => 'event']).");
        }

        $userId = Viewer::id($user, 'record an interaction');
        $itemId = $item instanceof Model ? $item->getKey() : $item;
        if (! is_int($itemId) && ! is_string($itemId)) {
            throw new InvalidArgumentException('Cannot record an interaction for an item without a key.');
        }

        return $source->record($userId, $itemId, $event, $once, $table, $at);
    }

    /**
     * Items similar to $item (people who interacted with it also interacted
     * with these), as cards, most similar first — e.g. a related-posts block:
     * `Recommender::similar('posts', $post, 6)`.
     *
     * Read from the neighbours build-neighbours computes; nothing new is
     * stored. The same for every viewer, so it is cached per item: warm
     * calls are cache reads only. Hidden items are left out (the model's
     * `recommendable` scope runs as a guest, or the feed's `filter`). Empty
     * until the item has neighbours — hide the block, or fall back to your
     * own query. `$limit` is capped at `neighbours_max`.
     *
     * @param  array<string, mixed>  $attributes  `list_vary_by` / `cards_vary_by` values (e.g. tenant, locale)
     * @return list<array<string, mixed>>
     */
    public function similar(string $feedName, Model|int|string $item, int $limit = 6, array $attributes = []): array
    {
        $id = $item instanceof Model ? $item->getKey() : $item;
        if (! is_int($id) && ! is_string($id)) {
            throw new InvalidArgumentException('Cannot find similar items for an item without a key.');
        }

        return $this->similarItems->for($this->feed($feedName), $id, $limit, $attributes);
    }

    /** Drop a cached card, e.g. after the item was edited or hidden. */
    public function forgetCard(string $feedName, int|string $id): void
    {
        $this->cache->forgetCard($this->feed($feedName), $id);
    }

    /**
     * How an item is doing, from the scores update-scores keeps:
     *
     *  - positive / exposure: e.g. likes / views (per `positive_events` / `exposure_events`)
     *  - rate: positive ÷ exposure (null without exposures)
     *  - quality: Wilson lower bound of that rate, 0..1 — a confident "how
     *    good", so 1 like out of 1 view does not beat 50 out of 100
     *  - popularity: time-decayed activity relative to the hottest item now, 0..1
     *
     * Items without any scored interaction are returned with zeros. With
     * `impressions` on, rate and quality use positive ÷ impressions (items
     * actually shown). Pass `$days` for the last N days only (today included);
     * popularity and last_event_at stay lifetime values.
     *
     * @param  int|string|Model|list<int|string|Model>  $items
     * @return array<int|string, array{positive: int, exposure: int, impressions: int, rate: ?float, quality: float, popularity: float, last_event_at: ?string}>
     */
    public function stats(string $feedName, int|string|Model|array $items, ?int $days = null): array
    {
        $ids = array_map(fn ($item) => $item instanceof Model ? $item->getKey() : $item, is_array($items) ? $items : [$items]);

        return $this->itemStats->for($this->feed($feedName), Ids::normalizeAll(array_filter($ids, fn ($id) => is_int($id) || is_string($id))), $days);
    }

    /**
     * Drop the cached card of $model in every feed whose `model` is its class
     * (used by the Recommendable trait on save / delete).
     */
    public function forgetModel(Model $model): void
    {
        $key = Value::id($model->getKey());
        if ($key === null) {
            return;
        }
        foreach ($this->registry->forModel($model::class) as $name) {
            $this->forgetCard($name, $key);
        }
    }

    /**
     * Forget a user's list and seen-set (e.g. "reset my recommendations").
     * With `list_vary_by`, pass the attributes of the variant to reset.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function forgetUser(string $feedName, Authenticatable|int|string $user, array $attributes = []): void
    {
        $userId = Viewer::id($user, 'forget a user');
        $feed = $this->feed($feedName);
        $this->cache->forgetList($feed, $userId, Variants::list($feed, $attributes));
        $this->cache->forgetSeen($feed, $userId, Variants::seen($feed, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes  the `list_vary_by` variant to drop
     */
    public function forgetTrending(string $feedName, array $attributes = []): void
    {
        $feed = $this->feed($feedName);
        $this->cache->forgetTrending($feed, Variants::list($feed, $attributes));
    }

    /**
     * Explain a user's ranking: per-source contributions of the top items.
     * For debugging / admin only — runs a full build without storing it.
     *
     * Rows are in blended-score order (before exploration and diversity).
     * `sources` are each source's raw score; `contributions` are the parts of
     * `score` (weight × normalised value), so they sum to `score`.
     * `final` is the 1-based position after exploration and diversity in this
     * fresh build (exploration is shuffled, so it can vary between runs);
     * `cached` is the position in the list the user is served right now
     * (null when not in it, or when nothing is cached). Exploration-only
     * items that reach the final top `$limit` are appended with
     * `exploration: true` and a zero score.
     *
     * @param  array<string, mixed>  $attributes  as passed to `page()`, so tenant-aware feeds explain what that tenant sees
     * @return list<array{id: int|string, score: float, sources: array<string, float>, contributions: array<string, float>, final: ?int, cached: ?int, exploration: bool}>
     */
    public function explain(string $feedName, Authenticatable|int|string|null $user, int $limit = 20, array $attributes = []): array
    {
        [$userId, $attributes] = Viewer::resolve($user, $attributes);
        $feed = $this->feed($feedName);
        $variant = Variants::list($feed, $attributes);
        $cached = $userId === null
            ? $this->cache->peekTrending($feed, $variant)
            : ($this->cache->list($feed, $userId, $variant)['ids'] ?? null);

        return $this->ranker->explain(new Context($feed, $userId, [], $attributes, CarbonImmutable::now()), $limit, $cached);
    }
}
