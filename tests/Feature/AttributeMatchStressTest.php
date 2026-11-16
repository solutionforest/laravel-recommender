<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Recommender\Content\AttributeMatcher;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Tests\Fixtures\Gadget;

/*
 * Attribute match at catalog scale: the same 200 items measured on a
 * 10,000-item and a 100,000-item catalog (500 categories, 3 of 2,000 tags
 * each; STRESS_CATEGORIES / STRESS_TAGS for a denser catalog). The query count must not change and latency must not follow the
 * catalog size: the pool is bounded by match_pool and every lookup is
 * indexed.
 *
 *     vendor/bin/pest --group=stress --filter=AttributeMatch
 *     RECOMMENDER_PERF=1 …  prints the numbers;  RECOMMENDER_STRESS_SCALE=n multiplies both sizes
 */
function dropStressGadgets(): void
{
    foreach (['gadget_tag', 'gadget_likes', 'gadgets'] as $table) {
        Schema::dropIfExists($table);
    }
}

function seedStressGadgets(int $items): void
{
    dropStressGadgets();
    Schema::create('gadgets', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('category_id');
        $table->decimal('price', 8, 2);
        $table->boolean('published')->default(true);
        $table->timestamps();
        $table->index('created_at');
        $table->index(['category_id', 'created_at']); // attribute match: newest per category
    });
    Schema::create('gadget_tag', function (Blueprint $table) {
        $table->unsignedBigInteger('gadget_id');
        $table->unsignedBigInteger('tag_id');
        $table->index(['tag_id', 'gadget_id']);
        $table->index(['gadget_id', 'tag_id']);
    });
    Schema::create('gadget_likes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('gadget_id');
        $table->timestamp('created_at')->nullable()->index();
        $table->index(['user_id', 'created_at']);
    });

    mt_srand(7);
    $tagCount = (int) (getenv('STRESS_TAGS') ?: 2000);
    $start = now()->subDays(365)->getTimestamp();
    $rows = $tags = [];
    for ($id = 1; $id <= $items; $id++) {
        $at = date('Y-m-d H:i:s', $start + mt_rand(0, 365 * 86400));
        $rows[] = ['id' => $id, 'category_id' => mt_rand(1, (int) (getenv('STRESS_CATEGORIES') ?: 500)), 'price' => mt_rand(100, 100000) / 100, 'published' => mt_rand(1, 20) !== 1, 'created_at' => $at, 'updated_at' => $at];
        foreach (array_unique([mt_rand(1, $tagCount), mt_rand(1, $tagCount), mt_rand(1, $tagCount)]) as $tag) {
            $tags[] = ['gadget_id' => $id, 'tag_id' => $tag];
        }
        if (count($rows) === 1000) {
            DB::table('gadgets')->insert($rows);
            $rows = [];
        }
        if (count($tags) >= 3000) {
            DB::table('gadget_tag')->insert($tags);
            $tags = [];
        }
    }
    $rows === [] || DB::table('gadgets')->insert($rows);
    $tags === [] || DB::table('gadget_tag')->insert($tags);
}

beforeEach(function () {
    config(['recommender.feeds' => ['gadgets' => [
        'model' => Gadget::class,
        'interactions' => ['gadget_likes' => 'like'],
        'match' => [
            'category_id' => 2.0,
            'tags' => ['weight' => 1.0, 'table' => 'gadget_tag', 'item' => 'gadget_id', 'value' => 'tag_id'],
            'price' => ['weight' => 1.0, 'type' => 'range'],
        ],
    ]]]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);
});

afterEach(fn () => dropStressGadgets());

it('matches attributes at the same query cost and latency on a 10x larger catalog', function () {
    $queries = function (callable $fn): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $fn();
        DB::flushQueryLog();

        return $n;
    };

    $measure = function (int $items) use ($queries): array {
        $t = hrtime(true);
        seedStressGadgets($items);
        $seedSeconds = (hrtime(true) - $t) / 1e9;

        $recommender = app(\SolutionForest\Recommender\Recommender::class);
        $feed = $recommender->feed('gadgets');
        $matcher = app(AttributeMatcher::class);
        $guest = new Context($feed, null);
        mt_srand(11);
        $probes = array_map(fn () => mt_rand(1, $items), range(1, 200));

        $one = $many = $cold = $warm = $oneQ = $manyQ = $coldQ = $warmQ = $manySql = [];
        $sqlMs = 0.0;
        DB::listen(function ($q) use (&$sqlMs) {
            $sqlMs += $q->time;
        });
        foreach ($probes as $i => $id) {
            $seeds = array_map(fn () => mt_rand(1, $items), range(1, 20));

            $t = hrtime(true);
            $oneQ[] = $queries(function () use ($matcher, $feed, $id, $guest) {
                expect($matcher->scores($feed, [$id], $guest, 30))->not->toBeEmpty();
            });
            $one[] = (hrtime(true) - $t) / 1e6;

            $t = hrtime(true);
            $before = $sqlMs;
            $manyQ[] = $queries(fn () => $matcher->scores($feed, $seeds, new Context($feed, 1), 150));
            $many[] = (hrtime(true) - $t) / 1e6;
            $manySql[] = $sqlMs - $before;

            $gadget = (new Gadget)->forceFill(['id' => $id])->syncOriginal();
            $t = hrtime(true);
            $coldQ[] = $queries(fn () => $gadget->similar(6));
            $cold[] = (hrtime(true) - $t) / 1e6;
            $t = hrtime(true);
            $warmQ[] = $queries(fn () => $gadget->similar(6));
            $warm[] = (hrtime(true) - $t) / 1e6;
        }

        return [
            'seed_s' => $seedSeconds,
            'one' => [percentile($one, 50), percentile($one, 95), max($oneQ)],
            'many' => [percentile($many, 50), percentile($many, 95), max($manyQ)],
            'many_sql' => percentile($manySql, 50),
            'cold' => [percentile($cold, 50), percentile($cold, 95), max(array_slice($coldQ, 1))], // first call also reads the neighbour version
            'warm' => [percentile($warm, 50), percentile($warm, 95), max($warmQ)],
        ];
    };

    $scale = max(1, (int) (getenv('RECOMMENDER_STRESS_SCALE') ?: 1));
    $sizes = [10_000 * $scale, 100_000 * $scale];
    $results = [];
    foreach ($sizes as $items) {
        $results[$items] = $r = $measure($items);
        perfNote(sprintf('attribute match on %s items (seeded in %.1fs, driver %s):', number_format($items), $r['seed_s'], DB::connection()->getDriverName()));
        foreach (['one' => 'scores, 1 seed (similar path)', 'many' => 'scores, 20 seeds (feed source)', 'cold' => 'similar(6) cold', 'warm' => 'similar(6) warm'] as $k => $label) {
            perfNote(sprintf('  %-32s p50 %6.2f ms  p95 %6.2f ms  max %d queries', $label, ...$r[$k]));
        }
        perfNote(sprintf('  of which SQL (20 seeds)          p50 %6.2f ms', $r['many_sql']));
    }

    [$small, $large] = array_values($results);
    foreach (['one', 'many', 'cold', 'warm'] as $k) {
        expect($large[$k][2])->toBe($small[$k][2]); // same query count at 10x the catalog
    }
    expect($small['one'][2])->toBe(5)   // seeds + seed tags + union of per-value lookups + candidates + their tags
        ->and($small['warm'][2])->toBe(0)
        // Generous: what matters is that it does not grow with the catalog (10x rows).
        ->and($large['one'][1])->toBeLessThan($small['one'][1] * 4 + 10)
        ->and($large['many'][1])->toBeLessThan($small['many'][1] * 4 + 20);
})->group('stress');
