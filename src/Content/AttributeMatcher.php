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
 *     'match_pool' => 200,    // candidates read per call, split across values
 *     'match_values' => 12,   // the seeds' strongest values looked up
 *
 * Nothing is stored and nothing compares all pairs. Per call: the seeds'
 * values (one query, plus one per tag table); for their strongest values
 * (at most `match_values`) the newest items of each, about
 * `match_pool / values` per value, read through an index (one UNION ALL
 * query); those candidates by primary key with the `recommendable` scope
 * (one query); their tags (one query per tag table). The work follows
 * match_pool, never how many items share a category or tag. Only those
 * candidates are scored, in PHP:
 *
 *     similarity(a, b) = Σ weight · sim_attribute(a, b) / Σ weight
 *
 * with sim = 1 for an equal value, |A ∩ B| / |A ∪ B| for tags, and
 * 1 − |a − b| / max(|a|, |b|) for a range. Index each match column as
 * (column, created_at) and each tag table on (value, item) and (item,
 * value); tag lookups take the newest items by key.
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

        // 2. A bounded pool: the seeds' strongest values (at most
        //    `match_values`), and for each value only its newest items,
        //    read through an index — so the cost follows match_pool, not
        //    how many items share a category or tag. One UNION ALL query.
        $values = [];   // "attribute\0value" => [attribute, value, weight]
        foreach ($seeds as $position => $seedId) {
            $recency = 1 / (1 + 0.1 * $position);
            foreach ($columns as $name => $column) {
                $value = $column['type'] === 'match' ? ($seedRows[(string) $seedId] ?? null)?->getAttribute($name) : null;
                if (is_int($value) || (is_string($value) && $value !== '')) {
                    $slot = $name."\0".$value;
                    $values[$slot] = [$name, $value, ($values[$slot][2] ?? 0.0) + $column['weight'] * $recency];
                }
            }
            foreach ($sets as $name => $set) {
                foreach (array_keys($seedSets[$name][(string) $seedId] ?? []) as $value) {
                    $slot = $name."\0".$value;
                    $values[$slot] = [$name, $value, ($values[$slot][2] ?? 0.0) + $set['weight'] * $recency];
                }
            }
        }
        if ($values === []) {
            return [];
        }
        uasort($values, fn (array $a, array $b) => $b[2] <=> $a[2]);
        $values = array_slice($values, 0, max(1, $feed->int('match_values', 12)));
        $pool = max(1, $feed->int('match_pool', 200));
        $perValue = max(10, (int) ceil($pool / count($values)));

        $created = $instance->usesTimestamps() && $instance->getCreatedAtColumn() ? $instance->getCreatedAtColumn() : null;
        $union = null;
        foreach ($values as [$name, $value]) {
            if (isset($columns[$name])) {
                // Index (column, created_at): a short range scan, newest first.
                $branch = $instance->newQuery()->toBase()
                    ->select([$key.' as candidate'])
                    ->where($name, $value)
                    ->when($created !== null, fn (QueryBuilder $q) => $q->orderByDesc((string) $created))
                    ->orderByDesc($key)
                    ->limit($perValue);
            } else {
                // Index (value, item): newest by key, no join or sort over the whole tag.
                $set = $sets[$name];
                $branch = $instance->getConnection()->table($set['table'])
                    ->select([$set['item'].' as candidate'])
                    ->where($set['value'], $value)
                    ->orderByDesc($set['item'])
                    ->limit($perValue);
            }
            // Wrapped so every database accepts ORDER BY / LIMIT per branch.
            $wrapped = $instance->getConnection()->query()->fromSub($branch, 'b'.($union === null ? 0 : count($union->unions ?? []) + 1))->select('candidate');
            $union = $union === null ? $wrapped : $union->unionAll($wrapped);
        }

        $ids = [];
        foreach ($union?->pluck('candidate') ?? [] as $candidate) {
            if (($id = Value::id($candidate)) !== null && ! in_array((string) $id, array_map('strval', $seeds), true)) {
                $ids[(string) $id] = Ids::normalize($id);
            }
        }
        if ($ids === []) {
            return [];
        }

        // 3. Those candidates that may be shown (the recommendable scope), by primary key.
        $pool = RecommendableQuery::for($instance, $context)
            ->whereKey(array_values($ids))
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

        // 4. Score the pool against every seed (recent seeds weigh more).
        //    Values are read from the models once: getAttribute() in the
        //    inner loop would dominate the cost.
        $read = function (?Model $row) use ($columns): array {
            $values = [];
            foreach ($columns as $name => $column) {
                $values[$name] = $row?->getAttribute($name);
            }

            return $values;
        };
        $seedValues = [];
        foreach ($seeds as $position => $seedId) {
            $seedValues[$position] = [(string) $seedId, $read($seedRows[(string) $seedId] ?? null), 1 / (1 + 0.1 * $position)];
        }
        $totalWeight = array_sum(array_column($columns, 'weight')) + array_sum(array_column($sets, 'weight'));
        $scores = [];
        foreach ($candidates as [$id, $candidate]) {
            $values = $read($candidate);
            $score = 0.0;
            foreach ($seedValues as [$seedKey, $seed, $recency]) {
                $similarity = 0.0;
                foreach ($columns as $name => $column) {
                    $similarity += $column['weight'] * ($column['type'] === 'match'
                        ? self::equal($seed[$name], $values[$name])
                        : self::closeness($seed[$name], $values[$name]));
                }
                foreach ($sets as $name => $set) {
                    $similarity += $set['weight'] * self::jaccard($seedSets[$name][$seedKey] ?? [], $poolSets[$name][(string) $id] ?? []);
                }
                $score += ($similarity / $totalWeight) * $recency;
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
     * @return array<string, array<string, true>> item id => set of values (as keys, for fast overlap)
     */
    private static function setValues(Model $model, array $set, array $ids): array
    {
        $out = [];
        // Tag tables live next to the model, on its connection.
        foreach ($model->getConnection()->table($set['table'])->whereIn($set['item'], $ids)->get([$set['item'], $set['value']]) as $row) {
            $item = Value::id($row->{$set['item']} ?? null);
            $value = Value::id($row->{$set['value']} ?? null);
            if ($item !== null && $value !== null) {
                $out[(string) $item][(string) $value] = true;
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
     * |A ∩ B| / |A ∪ B| on value sets keyed by value.
     *
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $shared = count(array_intersect_key($a, $b));

        return $shared / (count($a) + count($b) - $shared);
    }
}
