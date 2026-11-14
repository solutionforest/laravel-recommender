<?php

namespace SolutionForest\Recommender\Insights;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\CatalogTail;
use SolutionForest\Recommender\Ranking\Ranker;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Value;
use Throwable;

/**
 * Runs each part of a feed once on real data and times it — before users or
 * the scheduled jobs ever hit a slow query (`recommender:doctor --probe`,
 * also run by `recommender:bootstrap`).
 *
 * Read-only: the same reads the jobs and a page build do, for one recent
 * user and for a guest, without writing scores, lists or caches. The job
 * reads are bounded (the last hour of interactions, the first chunk of
 * users), so a missing index shows up as one slow query, not as a scan of
 * the whole history.
 */
final class SpeedProbe
{
    public function __construct(
        private readonly SlowWatch $watch,
        private readonly Bindings $bindings,
        private readonly Ranker $ranker,
    ) {
    }

    /**
     * @return list<array{part: string, ms: float, limit: int, slow: bool, detail: ?string}>
     */
    public function run(FeedDefinition $feed): array
    {
        $rows = [];
        $now = CarbonImmutable::now();
        $queryLimit = $feed->int('slow_query_ms', 1000);
        $partLimit = $feed->int('slow_ms', 200);

        $source = $this->bindings->interactions($feed);
        $user = null;
        if ($source !== null) {
            // Job reads: what update-scores and build-neighbours do on every run.
            $rows[] = $this->step('update-scores: read the last hour', $queryLimit, function () use ($source, $now, &$user): string {
                $events = 0;
                foreach ($source->between($now->subHour(), $now) as $interaction) {
                    $user ??= $interaction->userId;
                    $events++;
                }

                return number_format($events).' events';
            });
            $rows[] = $this->step('build-neighbours: read the first chunk of users', $queryLimit, function () use ($source, $feed, $now, &$user): string {
                foreach ($source->userHistories($now->subDays(max(1, $feed->int('history_days', 90))), max(1, $feed->int('history_per_user', 30))) as $userId => $items) {
                    $user ??= $userId;

                    return '1 chunk read';
                }

                return 'no history';
            });
            if ($user !== null) {
                $rows[] = $this->step("recent items of user {$user}", $queryLimit, fn () => count($source->recentItems($user, max(1, $feed->int('recent_items', 20)))).' items');
            }
        }

        // Serving: one personal build (when there is a recent user) and the guest list.
        $ids = [];
        foreach (array_filter([$user !== null ? $user : false, null], fn ($u) => $u !== false) as $viewer) {
            $label = $viewer === null ? 'guest list' : "personal list for user {$viewer}";
            $rows[] = $this->step($label, $partLimit, function () use ($feed, $viewer, $now, &$ids): string {
                $built = $this->ranker->rank(new Context($feed, $viewer, [], [], $now), $feed->int('page_size', 12) * 3, $viewer === null);
                $ids = $ids === [] ? $built : $ids;

                return count($built).' items';
            }, parts: true);
        }

        $model = $feed->get('model');
        if ($feed->get('tail', true) && is_string($model) && is_a($model, Model::class, true)) {
            $order = $feed->get('tail_order');
            $tail = new CatalogTail($model, is_string($order) && $order !== '' ? $order : null);
            $rows[] = $this->step('catalog tail (one page)', $partLimit, function () use ($tail, $feed, $now, &$ids): string {
                $more = $tail->ids(new Context($feed, null, [], [], $now), [], $feed->int('page_size', 12) * 3);
                $ids = $ids === [] ? $more : $ids; // before the first scores, test the scope and cards on catalog items

                return count($more).' items';
            });
        }

        if ($ids !== []) {
            $sample = array_slice($ids, 0, $feed->int('page_size', 12) * 3);
            $rows[] = $this->step('visibility check (recommendable scope / filter) for '.count($sample).' items', $partLimit, fn () => count($this->ranker->allowed(new Context($feed, $user, [], [], $now), $sample)).' allowed', parts: true);
            $rows[] = $this->step('cards for one page', $partLimit, function () use ($feed, $ids): string {
                return count($this->bindings->cards($feed)->cards(array_slice($ids, 0, $feed->int('page_size', 12)))).' cards';
            });
        }

        return array_merge(...$rows);
    }

    /**
     * Time one step; with $parts, also list each source / booster / scope /
     * groups call made inside it. Slow SQL inside a step is named.
     *
     * @param  callable(): string  $call
     * @return list<array{part: string, ms: float, limit: int, slow: bool, detail: ?string}>
     */
    private function step(string $label, int $limit, callable $call, bool $parts = false): array
    {
        $start = hrtime(true);
        try {
            [$detail, $captured] = $this->watch->capture($call);
        } catch (Throwable $e) {
            return [['part' => $label, 'ms' => (hrtime(true) - $start) / 1e6, 'limit' => $limit, 'slow' => false, 'detail' => 'failed: '.$e->getMessage()]];
        }
        $ms = (hrtime(true) - $start) / 1e6;

        $slowest = null;
        foreach ($captured as $entry) {
            if ($entry['kind'] === 'query' && ($slowest === null || $entry['ms'] > $slowest['ms'])) {
                $slowest = $entry;
            }
        }
        $detail = Value::string($detail);
        if ($slowest !== null && $slowest['ms'] >= $limit) {
            $detail .= sprintf('; slowest SQL %d ms: %s', (int) $slowest['ms'], $slowest['name']);
        }

        $rows = [['part' => $label, 'ms' => $ms, 'limit' => $limit, 'slow' => $ms >= $limit, 'detail' => $detail]];
        if ($parts) {
            foreach ($captured as $entry) {
                if ($entry['kind'] !== 'query') {
                    $rows[] = ['part' => "  {$entry['kind']} {$entry['name']}", 'ms' => $entry['ms'], 'limit' => $limit, 'slow' => $entry['ms'] >= $limit, 'detail' => null];
                }
            }
        }

        return $rows;
    }
}
