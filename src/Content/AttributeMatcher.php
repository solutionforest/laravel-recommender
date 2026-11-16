<?php

namespace SolutionForest\Recommender\Content;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use SolutionForest\Recommender\Eloquent\RecommendableQuery;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Ids;
use SolutionForest\Recommender\Support\Value;

/**
 * "Items like these" from the items' own attributes, for items nobody has
 * interacted with yet (new products, new posts). Configured per feed:
 *
 *     'match' => [
 *         'category_id' => 2.0,                                   // same value (a column)
 *         'tags' => ['weight' => 1.0, 'table' => 'post_tag', 'item' => 'post_id', 'value' => 'tag_id'], // overlap (Jaccard)
 *         'price' => ['weight' => 0.5, 'type' => 'range'],        // closeness (a numeric column)
 *     ],
 *     'match_pool' => 200,
 *
 * Nothing is stored and nothing compares all pairs. Per call: the seeds'
 * values (one query, plus one per tag table), then at most `match_pool`
 * candidates that share at least one value (one query, newest first, the
 * `recommendable` scope applied), then their tags (one query per tag
 * table). Only those candidates are scored, in PHP:
 *
 *     similarity(a, b) = Σ weight · sim_attribute(a, b) / Σ weight
 *
 * with sim = 1 for an equal value, |A ∩ B| / |A ∪ B| for tags, and
 * 1 − |a − b| / max(|a|, |b|) for a range. Index each match column and
 * each tag table on (value, item) and (item, value).
 */
final class AttributeMatcher
{
    /**
     * Whether the feed has a usable `match` config.
     */
    public function configured(FeedDefinition $feed): bool
    {
        return $feed->get('match') !== null && $feed->get('match') !== [];
    }

    /**
     * Candidates similar to the seeds, best first. Seeds earlier in the list
     * count more (most recent first, like CoInteractionSource).
     *
     * @param  list<int|string>  $seeds
     * @return array<int|string, float> candidate id => score
     */
    public function scores(FeedDefinition $feed, array $seeds, Context $context, int $limit): array
    {
        if ($seeds === [] || $limit <= 0) {
            return [];
        }

        [$model, $columns, $sets] = self::definition($feed);
        $instance = new $model;
        $key = $instance->getKeyName();
        $qualifiedKey = $instance->getQualifiedKeyName();

        // 1. The seeds' own values.
        $seedRows = [];
        if ($columns !== []) {
            foreach ($instance->newQuery()->whereKey($seeds)->get([$key, ...array_keys($columns)]) as $row) {
                $seedRows[(string) Value::id($row->getKey())] = $row;
            }
        }
        $seedSets = [];
        foreach ($sets as $name => $set) {
            $seedSets[$name] = self::setValues($instance, $set, $seeds);
        }

        // 2. A bounded pool of candidates sharing at least one value.
        /** @var list<\Closure(Builder<Model>): void> $shared */
        $shared = [];
        foreach ($columns as $name => $column) {
            if ($column['type'] !== 'match') {
                continue;
            }
            $values = [];
            foreach ($seedRows as $row) {
                $value = $row->getAttribute($name);
                if (is_int($value) || (is_string($value) && $value !== '')) {
                    $values[(string) $value] = $value;
                }
            }
            if ($values !== []) {
                $column = $instance->qualifyColumn($name);
                $shared[] = function (Builder $q) use ($column, $values): void {
                    $q->orWhereIn($column, array_values($values));
                };
            }
        }
        foreach ($sets as $name => $set) {
            $values = [];
            foreach ($seedSets[$name] as $itemValues) {
                foreach ($itemValues as $value) {
                    $values[(string) $value] = $value;
                }
            }
            if ($values !== []) {
                $shared[] = function (Builder $q) use ($qualifiedKey, $set, $values): void {
                    $q->orWhereIn($qualifiedKey, function (QueryBuilder $sub) use ($set, $values): void {
                        $sub->from($set['table'])->whereIn($set['value'], array_values($values))->select($set['item']);
                    });
                };
            }
        }
        if ($shared === []) {
            return [];
        }

        $order = $instance->usesTimestamps() && $instance->getCreatedAtColumn() ? $instance->qualifyColumn($instance->getCreatedAtColumn()) : $qualifiedKey;
        $pool = RecommendableQuery::for($instance, $context)
            ->whereNotIn($qualifiedKey, $seeds)
            ->where(function (Builder $q) use ($shared): void {
                foreach ($shared as $apply) {
                    $apply($q);
                }
            })
            ->orderByDesc($order)
            ->orderByDesc($qualifiedKey)
            ->limit(max(1, $feed->int('match_pool', 200)))
            ->get(array_map(fn (string $c) => $instance->qualifyColumn($c), [$key, ...array_keys($columns)]));

        $candidates = [];
        foreach ($pool as $row) {
            if (($id = Value::id($row->getKey())) !== null) {
                $candidates[] = [Ids::normalize($id), $row];
            }
        }
        if ($candidates === []) {
            return [];
        }
        $poolSets = [];
        foreach ($sets as $name => $set) {
            $poolSets[$name] = self::setValues($instance, $set, array_column($candidates, 0));
        }

        // 3. Score the pool against every seed (recent seeds weigh more).
        $totalWeight = array_sum(array_column($columns, 'weight')) + array_sum(array_column($sets, 'weight'));
        $scores = [];
        foreach ($candidates as [$id, $candidate]) {
            $score = 0.0;
            foreach ($seeds as $position => $seedId) {
                $seed = $seedRows[(string) $seedId] ?? null;
                $similarity = 0.0;
                foreach ($columns as $name => $column) {
                    $similarity += $column['weight'] * ($column['type'] === 'match'
                        ? self::equal($seed?->getAttribute($name), $candidate->getAttribute($name))
                        : self::closeness($seed?->getAttribute($name), $candidate->getAttribute($name)));
                }
                foreach ($sets as $name => $set) {
                    $similarity += $set['weight'] * self::jaccard($seedSets[$name][(string) $seedId] ?? [], $poolSets[$name][(string) $id] ?? []);
                }
                $score += ($similarity / $totalWeight) / (1 + 0.1 * $position);
            }
            if ($score > 0) {
                $scores[$id] = $score;
            }
        }

        arsort($scores);

        return array_slice($scores, 0, $limit, true);
    }

    /**
     * The feed's model and its normalised `match` config, split into model
     * columns and tag tables; throws with a fix when the config cannot work.
     *
     * @return array{0: class-string<Model>, 1: array<string, array{type: 'match'|'range', weight: float}>, 2: array<string, array{weight: float, table: string, item: string, value: string}>}
     */
    public static function definition(FeedDefinition $feed): array
    {
        $model = $feed->get('model');
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException("Feed [{$feed->name}] 'match' needs the feed's 'model'.");
        }

        $columns = $sets = [];
        foreach (Value::map($feed->get('match', [])) as $name => $definition) {
            $definition = is_array($definition) ? $definition : ['weight' => $definition];
            $weight = Value::float($definition['weight'] ?? 1.0);
            $table = Value::string($definition['table'] ?? '');
            $type = Value::string($definition['type'] ?? ($table !== '' ? 'set' : 'match'));
            if (! is_string($name) || ! in_array($type, ['match', 'set', 'range'], true) || $weight <= 0) {
                throw new InvalidArgumentException("Feed [{$feed->name}] 'match' entries are column => weight, or ['weight' => …, 'type' => 'match' | 'range'], or ['weight' => …, 'table' => …, 'item' => …, 'value' => …] for tags.");
            }
            if ($type === 'match' || $type === 'range') {
                $columns[$name] = ['type' => $type, 'weight' => $weight];

                continue;
            }
            $item = Value::string($definition['item'] ?? '');
            $value = Value::string($definition['value'] ?? '');
            if ($table === '' || $item === '' || $value === '') {
                throw new InvalidArgumentException("Feed [{$feed->name}] 'match.{$name}' needs 'table', 'item' and 'value' (e.g. 'post_tag', 'post_id', 'tag_id').");
            }
            $sets[$name] = ['weight' => $weight, 'table' => $table, 'item' => $item, 'value' => $value];
        }

        if ($sets === [] && ! in_array('match', array_column($columns, 'type'), true)) {
            throw new InvalidArgumentException("Feed [{$feed->name}] 'match' needs at least one column or tag table to find candidates by ('range' only scores them).");
        }

        return [$model, $columns, $sets];
    }

    /**
     * @param  array{weight: float, table: string, item: string, value: string}  $set
     * @param  list<int|string>  $ids
     * @return array<string, list<int|string>> item id => values
     */
    private static function setValues(Model $model, array $set, array $ids): array
    {
        $out = [];
        // Tag tables live next to the model, on its connection.
        foreach ($model->getConnection()->table($set['table'])->whereIn($set['item'], $ids)->get([$set['item'], $set['value']]) as $row) {
            $item = Value::id($row->{$set['item']} ?? null);
            $value = Value::id($row->{$set['value']} ?? null);
            if ($item !== null && $value !== null) {
                $out[(string) $item][] = $value;
            }
        }

        return $out;
    }

    private static function equal(mixed $a, mixed $b): float
    {
        if (! is_scalar($a) || ! is_scalar($b) || $a === '') {
            return 0.0;
        }

        return (string) $a === (string) $b ? 1.0 : 0.0; // '10' from one driver equals 10 from another
    }

    private static function closeness(mixed $a, mixed $b): float
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return 0.0;
        }
        $scale = max(abs((float) $a), abs((float) $b));

        return $scale == 0.0 ? 1.0 : max(0.0, 1 - abs((float) $a - (float) $b) / $scale);
    }

    /**
     * @param  list<int|string>  $a
     * @param  list<int|string>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $a = array_unique(array_map('strval', $a));
        $b = array_unique(array_map('strval', $b));
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }
}
