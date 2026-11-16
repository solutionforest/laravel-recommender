<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Recommender\Content\AttributeMatcher;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Sources\AttributeMatchSource;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Tests\Fixtures\Gadget;

/*
 * Attribute match: items like these from their own category / tags / price,
 * for items nobody has interacted with yet. Bounded pool, nothing stored.
 */
function dropGadgetTables(): void
{
    foreach (['gadget_likes', 'gadget_tag', 'gadgets'] as $table) {
        Schema::dropIfExists($table);
    }
}

// Tables are created per test. On MySQL, DDL survives the test (unlike
// in-memory SQLite), so drop them before and after each test.
afterEach(fn () => dropGadgetTables());

beforeEach(function () {
    dropGadgetTables();
    Schema::create('gadgets', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('category_id')->index();
        $table->decimal('price', 8, 2);
        $table->boolean('published')->default(true);
        $table->timestamps();
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

    // id => [category, price, tags, published]
    $catalog = [
        1 => [10, 100, [1, 2], true],   // the seed
        2 => [10, 100, [1, 2], true],   // same everything
        3 => [10, 300, [3], true],      // same category only
        4 => [20, 100, [1, 2], true],   // same tags + price, other category
        5 => [20, 900, [9], true],      // nothing shared
        6 => [10, 100, [1, 2], false],  // like 2, but hidden
    ];
    foreach ($catalog as $id => [$category, $price, $tags, $published]) {
        Gadget::create(['id' => $id, 'category_id' => $category, 'price' => $price, 'published' => $published, 'created_at' => now()->subDays(10 - $id)]);
        foreach ($tags as $tag) {
            DB::table('gadget_tag')->insert(['gadget_id' => $id, 'tag_id' => $tag]);
        }
    }
});

function gadgetContext(?int $userId = null): Context
{
    return new Context(Recommender::feed('gadgets'), $userId);
}

it('ranks candidates by weighted category, tag and price similarity', function () {
    $scores = app(AttributeMatcher::class)->scores(Recommender::feed('gadgets'), [1], gadgetContext(), 10);

    // 2 shares everything (1.0); 4 shares tags + price (0.5); 3 shares the category (0.5) + a third of the price (~0.58)
    expect(array_keys($scores))->toBe([2, 3, 4])
        ->and($scores[2])->toEqualWithDelta(1.0, 0.0001)
        ->and($scores[4])->toEqualWithDelta(0.5, 0.0001)
        ->and($scores[3])->toEqualWithDelta((2.0 + 1 / 3) / 4, 0.0001)
        ->and($scores)->not->toHaveKey(5)   // shares nothing: never even fetched
        ->and($scores)->not->toHaveKey(6)   // hidden by the recommendable scope
        ->and($scores)->not->toHaveKey(1);  // never the seed itself
});

it('keeps the candidate pool bounded and newest first', function () {
    config(['recommender.feeds.gadgets.match_pool' => 1]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    // Newest sharing a value is 4 (created last among 2..4).
    expect(array_keys(app(AttributeMatcher::class)->scores(Recommender::feed('gadgets'), [1], gadgetContext(), 10)))->toBe([4]);
});

it('feeds new items to signed-in users from what they interacted with', function () {
    DB::table('gadget_likes')->insert(['user_id' => 7, 'gadget_id' => 1, 'created_at' => now()->subHour()]);

    $scores = app(AttributeMatchSource::class)->candidates(gadgetContext(7), 10);

    expect(array_keys($scores))->toBe([2, 3, 4])
        ->and(app(AttributeMatchSource::class)->candidates(gadgetContext(), 10))->toBe([]); // guests: no recent items
});

it('lets similar() fall back to attributes for an item without neighbours', function () {
    // build-neighbours never ran: no neighbour version at all.
    expect(array_column(Gadget::find(1)->similar(3), 'id'))->toBe([2, 3, 4]);

    // Without a match config the old behaviour stays: nothing.
    config(['recommender.feeds.gadgets.match' => null]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);
    cache()->flush();
    expect(Gadget::find(1)->similar())->toBe([]);
});

it('runs a fixed number of queries, whatever the catalog size', function () {
    $count = function (callable $fn): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $fn();

        return $n;
    };
    $matcher = app(AttributeMatcher::class);
    $feed = Recommender::feed('gadgets');

    $small = $count(fn () => $matcher->scores($feed, [1], gadgetContext(), 10));

    foreach (range(100, 400) as $id) {
        Gadget::create(['id' => $id, 'category_id' => 10, 'price' => 100, 'published' => true]);
        DB::table('gadget_tag')->insert(['gadget_id' => $id, 'tag_id' => 1]);
    }
    $large = $count(fn () => $matcher->scores($feed, [1], gadgetContext(), 10));

    // seeds + seed tags + pool + pool tags
    expect($small)->toBe(4)->and($large)->toBe($small);

    // similar(): cold = version + match queries + filter + cards; warm = none.
    $gadget = Gadget::find(2);
    $gadget->similar();
    expect($count(fn () => $gadget->similar()))->toBe(0);
});

it('rejects a match config that cannot find candidates', function (array $match, string $message) {
    config(['recommender.feeds.gadgets.match' => $match]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    expect(fn () => app(AttributeMatcher::class)->scores(Recommender::feed('gadgets'), [1], gadgetContext(), 10))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'range only' => [['price' => ['type' => 'range']], 'at least one column or tag table'],
    'tags without columns' => [['tags' => ['table' => 'gadget_tag']], "needs 'table', 'item' and 'value'"],
    'bad weight' => [['category_id' => 0], 'entries are column => weight'],
]);

it('prefers neighbours and falls back per item only when an item has none', function () {
    $likes = [];
    foreach (range(1, 5) as $u) {
        $likes[] = ['user_id' => $u, 'gadget_id' => 2, 'created_at' => now()->subHour()];
        $likes[] = ['user_id' => $u, 'gadget_id' => 5, 'created_at' => now()->subHour()];
    }
    DB::table('gadget_likes')->insert($likes);
    $this->artisan('recommender:build-neighbours', ['feed' => 'gadgets'])->assertSuccessful();

    expect(array_column(Gadget::find(2)->similar(), 'id'))->toBe([5])      // co-liked, though nothing in common
        ->and(array_column(Gadget::find(1)->similar(3), 'id'))->toBe([2, 3, 4]); // no neighbours: attributes
});

it('is checked by recommender:doctor', function () {
    $labels = fn () => collect(app(\SolutionForest\Recommender\Dashboard\Health::class)->checks(app(\SolutionForest\Recommender\Recommender::class)->feed('gadgets')))->filter(fn ($c) => str_starts_with($c['label'], 'match'))->map(fn ($c) => $c['level'].' '.$c['label'])->values()->all();

    expect($labels())->toContain('ok match: gadgets.category_id (match)', 'ok match: gadgets.price (range)', 'ok match: gadget_tag (tags)');

    config(['recommender.feeds.gadgets.match' => ['colour' => 1.0, 'tags' => ['table' => 'missing_tags', 'item' => 'gadget_id', 'value' => 'tag_id']]]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);
    $this->app->forgetInstance(\SolutionForest\Recommender\Dashboard\Health::class);

    expect($labels())->toContain('fail match: gadgets has no column colour', 'fail match: tag table [missing_tags] does not exist');
});
