<?php

namespace SolutionForest\Recommender\Ranking;

use Illuminate\Contracts\Container\Container;
use SolutionForest\Recommender\Contracts\Booster;
use SolutionForest\Recommender\Contracts\CandidateSource;
use SolutionForest\Recommender\Scoring\Blender;
use SolutionForest\Recommender\Scoring\Diversifier;
use SolutionForest\Recommender\Scoring\Explorer;
use SolutionForest\Recommender\Scoring\Jitter;
use SolutionForest\Recommender\Sources\TrendingSource;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Support\Report;
use Throwable;

/**
 * Turns a Context into an ordered list of item ids:
 * sources → filter → blend → boosters → exploration → diversity.
 *
 * A broken source or booster is logged and skipped, never fatal.
 */
final class Ranker
{
    public function __construct(
        private readonly Container $container,
        private readonly Bindings $bindings,
        private readonly Blender $blender = new Blender(),
        private readonly Explorer $explorer = new Explorer(),
        private readonly Diversifier $diversifier = new Diversifier(),
        private readonly Jitter $jitter = new Jitter(),
    ) {
    }

    /**
     * @return list<int|string>
     */
    public function rank(Context $context, int $size, bool $trendingOnly = false): array
    {
        $parts = $this->collect($context, $context->feed->int('source_limit', 200), $trendingOnly);

        return $this->order($context, $parts, $size, $trendingOnly);
    }

    /**
     * Gather candidates from every source, filter once, blend.
     *
     * @return array{sources: array<string, array<int|string, float>>, boosts: array<string, array<int|string, float>>, blended: array<int|string, float>, exploration: list<int|string>, weights: array<string, float>, boost_weights: array<string, float>}
     */
    public function collect(Context $context, int $limit, bool $trendingOnly): array
    {
        $feed = $context->feed;
        $sourceWeights = $trendingOnly
            ? ($feed->weighted('trending_sources') ?: [TrendingSource::class => 1.0]) // guests / fallback: no user needed
            : $feed->weighted('sources');
        $explorationClasses = $trendingOnly ? [] : $feed->list('exploration_sources');

        $results = [];
        foreach (array_unique(array_merge(array_keys($sourceWeights), $explorationClasses)) as $class) {
            try {
                /** @var CandidateSource $source */
                $source = $this->container->make($class);
                $results[$class] = $source->candidates($context, $limit);
            } catch (Throwable $e) {
                Report::failure($feed, "source {$class}", $e); // a broken source must not break the feed
                $results[$class] = [];
            }
        }

        $seen = array_flip($context->seen);
        $all = [];
        foreach ($results as $scores) {
            foreach ($scores as $id => $score) {
                if (! isset($seen[$id])) {
                    $all[$id] = true;
                }
            }
        }

        $allowed = array_flip($this->allowed($context, array_keys($all)));
        foreach ($results as $class => $scores) {
            $results[$class] = array_intersect_key($scores, $allowed);
        }

        $sourceResults = array_intersect_key($results, $sourceWeights);
        $blended = $this->blender->blend($sourceResults, $sourceWeights);

        $boostWeights = $trendingOnly ? [] : $feed->weighted('boosters');
        $boosts = [];
        if ($boostWeights !== [] && $blended !== []) {
            $candidateIds = array_keys($blended);
            foreach ($boostWeights as $class => $weight) {
                try {
                    /** @var Booster $booster */
                    $booster = $this->container->make($class);
                    $boosts[$class] = $booster->boost($context, $candidateIds);
                } catch (Throwable $e) {
                    Report::failure($feed, "booster {$class}", $e);
                }
            }
            $blended = $this->blender->blend($sourceResults, $sourceWeights, $boosts, $boostWeights);
        }

        $exploration = [];
        foreach ($explorationClasses as $class) {
            foreach (array_keys($results[$class] ?? []) as $id) {
                $exploration[$id] = true;
            }
        }

        return [
            'sources' => $sourceResults,
            'boosts' => $boosts,
            'blended' => $blended,
            'exploration' => array_keys($exploration),
            'weights' => $sourceWeights,
            'boost_weights' => $boostWeights,
        ];
    }

    /**
     * Blended order → exploration interleave → diversity.
     *
     * @param  array{blended: array<int|string, float>, exploration: list<int|string>}  $parts
     * @return list<int|string>
     */
    public function order(Context $context, array $parts, int $size, bool $trendingOnly): array
    {
        $feed = $context->feed;

        $explore = $trendingOnly ? [] : $parts['exploration'];
        shuffle($explore);

        // Optional randomness (classic "random order" feeds); never for the shared trending list.
        $blended = $trendingOnly ? $parts['blended'] : $this->jitter->apply($parts['blended'], $feed->float('jitter', 0.0));

        $ids = $this->explorer->interleave(array_slice(array_keys($blended), 0, $size), $explore, $feed->float('exploration_ratio', 0.15));
        $ids = array_slice($ids, 0, $size);

        $diversity = (array) $feed->get('diversity', []);
        $groups = $diversity !== [] && $ids !== [] ? $this->bindings->groups($feed) : null;
        if ($groups !== null) {
            $ids = $this->diversifier->apply($ids, $groups->groups($ids), $diversity, $feed->int('page_size', 12));
        }

        return Ids::normalizeAll($ids);
    }

    /**
     * The subset of $ids the feed's filter allows (all of them without a filter).
     *
     * @param  list<int|string>  $ids
     * @return list<int|string>
     */
    public function allowed(Context $context, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $filter = $this->bindings->filter($context->feed);

        return $filter === null ? $ids : $filter->allowed($context, Ids::normalizeAll($ids));
    }

    /**
     * Append candidates that pass the filter and are neither excluded nor queued.
     *
     * @param  list<int|string>  $queue
     * @param  list<int|string>  $candidates
     * @param  array<int|string, mixed>  $exclude
     * @return list<int|string>
     */
    public function appendAllowed(Context $context, array $queue, array $candidates, array $exclude = [], ?int $limit = null): array
    {
        $have = array_flip($queue);
        $pending = [];
        foreach ($candidates as $id) {
            if (! isset($exclude[$id]) && ! isset($have[$id])) {
                $pending[] = $id;
                $have[$id] = true;
            }
        }
        if ($pending === []) {
            return $queue;
        }

        $allowed = array_flip($this->allowed($context, $pending));
        foreach ($pending as $id) {
            if ($limit !== null && count($queue) >= $limit) {
                break;
            }
            if (isset($allowed[$id])) {
                $queue[] = $id;
            }
        }

        return $queue;
    }

    /**
     * Per-source breakdown of a fresh build — see Recommender::explain().
     *
     * @param  list<int|string>|null  $cachedList  the list the viewer is served now
     * @return list<array{id: int|string, score: float, sources: array<string, float>, contributions: array<string, float>, final: ?int, cached: ?int, exploration: bool}>
     */
    public function explain(Context $context, int $limit, ?array $cachedList): array
    {
        $feed = $context->feed;
        $guest = $context->isGuest();
        $parts = $this->collect($context, $feed->int('source_limit', 200), $guest);
        $final = array_flip(array_map('strval', $this->order($context, $parts, $feed->int('feed_size', 200), $guest)));
        $cached = $cachedList === null ? [] : array_flip(array_map('strval', $cachedList));

        $max = [];
        foreach ($parts['sources'] as $class => $scores) {
            $max[$class] = $scores === [] ? 0.0 : max($scores);
        }

        $out = [];
        foreach (array_slice($parts['blended'], 0, $limit, true) as $id => $score) {
            $sources = $contributions = [];
            foreach ($parts['sources'] as $class => $scores) {
                if (isset($scores[$id])) {
                    $sources[class_basename($class)] = round($scores[$id], 4);
                    $weight = $parts['weights'][$class] ?? 0.0;
                    if ($weight > 0 && $max[$class] > 0) {
                        $contributions[class_basename($class)] = round($weight * max(0.0, $scores[$id]) / $max[$class], 4);
                    }
                }
            }
            foreach ($parts['boosts'] as $class => $values) {
                if (isset($values[$id])) {
                    $sources[class_basename($class)] = round($values[$id], 4);
                    $weight = $parts['boost_weights'][$class] ?? 0.0;
                    if ($weight > 0) {
                        $contributions[class_basename($class)] = round($weight * min(1.0, max(0.0, $values[$id])), 4);
                    }
                }
            }
            $out[] = [
                'id' => $id,
                'score' => round($score, 4),
                'sources' => $sources,
                'contributions' => $contributions,
                'final' => self::position($final, $id),
                'cached' => self::position($cached, $id),
                'exploration' => false,
            ];
        }

        // Exploration-only items that made it into the final top $limit.
        $listed = array_flip(array_map(fn ($row) => (string) $row['id'], $out));
        foreach (array_slice(array_keys($final), 0, $limit) as $id) {
            if (! isset($listed[(string) $id]) && ! isset($parts['blended'][$id])) {
                $out[] = [
                    'id' => Ids::normalize((string) $id),
                    'score' => 0.0,
                    'sources' => [],
                    'contributions' => [],
                    'final' => self::position($final, $id),
                    'cached' => self::position($cached, $id),
                    'exploration' => true,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $map  id => 0-based index
     */
    private static function position(array $map, int|string $id): ?int
    {
        return isset($map[(string) $id]) ? $map[(string) $id] + 1 : null;
    }
}
