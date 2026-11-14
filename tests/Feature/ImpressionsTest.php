<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Dashboard\Health;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Recommender as RecommenderService;
use SolutionForest\Recommender\Storage\ScoreStore;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    config(['recommender.feeds.articles.page_size' => 3, 'recommender.feeds.articles.tail' => false]);
    $this->app->forgetInstance(RecommenderService::class);

    foreach (range(1, 6) as $i) {
        Article::create(['id' => $i, 'author_id' => 100 + $i, 'title' => "Article {$i}"]);
    }

    // Article 1: a weak month, a strong week. Article 2: steady.
    $rows = ['likes' => [], 'views' => []];
    foreach (range(1, 20) as $u) {
        $rows['views'][] = ['user_id' => $u, 'article_id' => 1, 'created_at' => now()->subDays(20)];
        if ($u <= 2) {
            $rows['likes'][] = ['user_id' => $u, 'article_id' => 1, 'created_at' => now()->subDays(20)];
        }
        $rows['views'][] = ['user_id' => 100 + $u, 'article_id' => 1, 'created_at' => now()->subDays(2)];
        if ($u <= 16) {
            $rows['likes'][] = ['user_id' => 100 + $u, 'article_id' => 1, 'created_at' => now()->subDays(2)];
        }
        $rows['views'][] = ['user_id' => 200 + $u, 'article_id' => 2, 'created_at' => now()->subDays(3)];
        if ($u <= 10) {
            $rows['likes'][] = ['user_id' => 200 + $u, 'article_id' => 2, 'created_at' => now()->subDays(3)];
        }
    }
    DB::table('article_views')->insert($rows['views']);
    DB::table('article_likes')->insert($rows['likes']);
    $this->artisan('recommender:rebuild-scores', ['feed' => 'articles', '--days' => 30, '--force' => true])->assertSuccessful();
});

function enableImpressions(bool $guests = false): void
{
    config(['recommender.feeds.articles.impressions' => true, 'recommender.feeds.articles.impressions_guests' => $guests]);
    app()->forgetInstance(RecommenderService::class);
}

it('does not count impressions unless enabled', function () {
    Recommender::page('articles', null);

    expect((int) DB::table('rec_item_scores')->sum('impressions'))->toBe(0)
        ->and(DB::table('rec_item_daily')->sum('impressions'))->toEqual(0);
});

it('counts each served item once per page, for guests (opt-in) and members', function () {
    enableImpressions(guests: true);

    $guest = Recommender::page('articles', null);
    Recommender::page('articles', null);                       // same page again
    $member = Recommender::page('articles', new GenericUser(['id' => 999]));

    $stats = Recommender::stats('articles', array_unique([...$guest->ids, ...$member->ids]));
    foreach ($guest->ids as $id) {
        expect($stats[$id]['impressions'])->toBe(2 + (in_array($id, $member->ids, true) ? 1 : 0));
    }
    expect((int) DB::table('rec_item_daily')->where('day', now()->toDateString())->sum('impressions'))
        ->toBe(2 * count($guest->ids) + count($member->ids));
});

it('rates quality against impressions when they are on', function () {
    enableImpressions();
    // Shown 200 times but liked 18: the views-based rate (18/40) overstates it.
    app(ScoreStore::class)->addImpressions('articles', [1], now());
    DB::table('rec_item_scores')->where('item_id', '1')->update(['impressions' => 200]);

    $stats = Recommender::stats('articles', 1)[1];

    expect($stats['rate'])->toBe(0.09)
        ->and($stats['quality'])->toBeLessThan(0.15);
});

it('reports a recent window and the trend next to lifetime numbers', function () {
    $life = Recommender::stats('articles', 1)[1];
    $week = Recommender::stats('articles', 1, days: 7)[1];

    expect($life)->toMatchArray(['positive' => 18, 'exposure' => 40])
        ->and($week)->toMatchArray(['positive' => 16, 'exposure' => 20, 'rate' => 0.8])
        ->and($week['quality'])->toBeGreaterThan($life['quality']);

    $trend = collect(app(Health::class)->trending(app(RecommenderService::class)->feed('articles')))->keyBy('id');
    expect($trend[1]['trend'])->toBe('up')      // much better this week than over the month
        ->and($trend[2]['trend'])->toBe('flat'); // all its activity is within the week
});

it('keeps impressions through a score rebuild', function () {
    enableImpressions(guests: true);
    $ids = Recommender::page('articles', null)->ids;

    $this->artisan('recommender:rebuild-scores', ['feed' => 'articles', '--days' => 30, '--force' => true])->assertSuccessful();

    expect(Recommender::stats('articles', $ids[0])[$ids[0]]['impressions'])->toBe(1)
        ->and(Recommender::stats('articles', 1)[1]['positive'])->toBe(18); // not double-counted
});

it('prunes old daily rows but keeps items that are still being shown', function () {
    enableImpressions();
    app(ScoreStore::class)->addImpressions('articles', [6], now()); // shown, never interacted with
    $cutoff = now()->addDays(200);

    $store = app(ScoreStore::class);
    $store->prune('articles', now()->subDay());
    expect(DB::table('rec_item_scores')->where('item_id', '6')->exists())->toBeTrue(); // shown today

    expect($store->pruneDaily('articles', $cutoff))->toBeGreaterThan(0)
        ->and(DB::table('rec_item_daily')->count())->toBe(0);
});

it('counts a signed-in user once per item: a refresh adds no impressions', function () {
    enableImpressions();
    $user = new GenericUser(['id' => 777]);

    $first = Recommender::page('articles', $user, 1);
    $sum = fn () => (int) DB::table('rec_item_scores')->sum('impressions');
    expect($sum())->toBe(count($first->ids));

    // Same items served again (the page-1 "never show an empty screen" fallback
    // after everything was seen) are not counted a second time.
    Recommender::forgetTrending('articles');
    app(SolutionForest\Recommender\Feed\FeedCache::class)->markSeen(Recommender::feed('articles'), 777, [1, 2, 3, 4, 5, 6]);
    $before = $sum();
    Recommender::page('articles', $user, 1);

    expect($sum())->toBe($before);
});

it('counts an impression only once when two requests race on the seen-set', function () {
    $feed = Recommender::feed('articles');
    $cache = app(SolutionForest\Recommender\Feed\FeedCache::class);

    expect($cache->markSeen($feed, 5, [10, 11]))->toBe([10, 11])
        ->and($cache->markSeen($feed, 5, [11, 12]))->toBe([12])
        ->and($cache->seen($feed, 5))->toBe([10, 11, 12]);
});

it('keeps counting every serve for guests when impressions_guests is on', function () {
    enableImpressions(guests: true);
    $ids = Recommender::page('articles', null)->ids;
    Recommender::page('articles', null);

    expect($ids)->not->toBeEmpty()
        ->and((int) DB::table('rec_item_scores')->sum('impressions'))->toBe(2 * count($ids));
});

it('does not count guest pages by default, so reloads cannot lower quality', function () {
    enableImpressions();
    $before = Recommender::stats('articles', [1, 2]);
    foreach (range(1, 50) as $i) {
        Recommender::page('articles', null, 1, 1);
    }

    expect((int) DB::table('rec_item_scores')->sum('impressions'))->toBe(0)
        ->and(Recommender::stats('articles', [1, 2]))->toBe($before);
});

it('serves the page even when the impression write fails', function () {
    enableImpressions();
    // The daily table is only written by impressions: dropping it makes the write fail.
    Illuminate\Support\Facades\Schema::drop('rec_item_daily');

    expect(Recommender::page('articles', new GenericUser(['id' => 9]), 1)->ids)->not->toBeEmpty();
});

it('writes impressions after the response when serving HTTP', function () {
    enableImpressions();
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), false);

    try {
        Recommender::page('articles', new GenericUser(['id' => 11]), 1);
        expect((int) DB::table('rec_item_scores')->sum('impressions'))->toBe(0);

        app()->terminate();
        expect((int) DB::table('rec_item_scores')->sum('impressions'))->toBeGreaterThan(0);
    } finally {
        $property->setValue(app(), true);
    }
});
