<?php

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Contracts\ItemFilter;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Support\Context;

/*
 * similar(): "more like this item" from the neighbours build-neighbours
 * computes. Viewer-independent and cached per item, so it stays flat as
 * users grow.
 */
beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    foreach (range(1, 8) as $i) {
        Article::create(['id' => $i, 'author_id' => 100 + $i, 'title' => "Article {$i}", 'published' => $i !== 3]);
    }

    // Readers of 1 like 2 most, then 3 (unpublished), then 4; 5 is unrelated.
    $when = now()->subHours(2);
    $likes = [];
    foreach (range(1, 12) as $u) {
        $likes[] = ['user_id' => $u, 'article_id' => 1, 'created_at' => $when];
        $likes[] = ['user_id' => $u, 'article_id' => 2, 'created_at' => $when];
        if ($u <= 8) {
            $likes[] = ['user_id' => $u, 'article_id' => 3, 'created_at' => $when];
        }
        if ($u <= 4) {
            $likes[] = ['user_id' => $u, 'article_id' => 4, 'created_at' => $when];
        }
    }
    foreach (range(20, 25) as $u) {
        $likes[] = ['user_id' => $u, 'article_id' => 5, 'created_at' => $when];
        $likes[] = ['user_id' => $u, 'article_id' => 6, 'created_at' => $when];
    }
    DB::table('article_likes')->insert($likes);

    $this->artisan('recommender:build-neighbours', ['feed' => 'articles'])->assertSuccessful();
});

function queriesDuring(callable $fn): int
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $fn();
    DB::flushQueryLog();

    return $count;
}

it('returns cards of the most similar items, never the item itself or a hidden one', function () {
    $similar = Article::find(1)->similar(6);

    expect(array_column($similar, 'id'))->toBe([2, 4]) // 3 is unpublished
        ->and($similar[0])->toBe(['id' => 2, 'title' => 'Article 2', 'author_id' => 102]);

    expect(array_column(Recommender::similar('articles', 5), 'id'))->toBe([6]);
});

it('caps the result at the requested limit and at neighbours_max', function () {
    expect(Article::find(1)->similar(1))->toHaveCount(1)
        ->and(array_column(Recommender::similar('articles', 1, 1000), 'id'))->toBe([2, 4]);
});

it('returns an empty list for an item without neighbours, or before neighbours were ever built', function () {
    expect(Article::find(8)->similar())->toBe([]);

    DB::table('rec_item_neighbours')->delete();
    DB::table('rec_state')->where('key', 'like', 'neighbours:%')->delete();
    cache()->flush();

    expect(Article::find(1)->similar())->toBe([]);
});

it('drops an item hidden after the list was cached', function () {
    expect(array_column(Article::find(1)->similar(), 'id'))->toBe([2, 4]);

    Article::find(2)->update(['published' => false]); // Recommendable forgets its card

    expect(array_column(Article::find(1)->similar(), 'id'))->toBe([4]);
});

it('switches to the new neighbours after a rebuild', function () {
    expect(array_column(Article::find(1)->similar(), 'id'))->toBe([2, 4]);

    // Article 7 becomes the strongest neighbour of 1.
    $likes = [];
    foreach (range(30, 60) as $u) {
        $likes[] = ['user_id' => $u, 'article_id' => 1, 'created_at' => now()->subHour()];
        $likes[] = ['user_id' => $u, 'article_id' => 7, 'created_at' => now()->subHour()];
    }
    DB::table('article_likes')->insert($likes);
    $this->artisan('recommender:build-neighbours', ['feed' => 'articles'])->assertSuccessful();

    expect(array_column(Article::find(1)->similar(), 'id')[0])->toBe(7);
});

it('applies a custom filter class (as a guest)', function () {
    app()->bind('test.similar.filter', fn () => new class implements ItemFilter
    {
        public function allowed(Context $context, array $ids): array
        {
            expect($context->userId)->toBeNull();

            return array_values(array_diff($ids, [2]));
        }
    });
    config(['recommender.feeds.articles.filter' => 'test.similar.filter']);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    expect(array_column(Recommender::similar('articles', 1), 'id'))->toBe([4]);
});

it('costs one neighbours row and one card query cold, and no query warm', function () {
    $article = Article::find(1);
    $other = Article::find(5);

    $first = queriesDuring(fn () => $article->similar()); // + the neighbour version, once per feed
    $cold = queriesDuring(fn () => $other->similar());
    $warm = queriesDuring(fn () => $article->similar());

    expect($first)->toBeLessThanOrEqual(3)
        ->and($cold)->toBeLessThanOrEqual(2)
        ->and($warm)->toBe(0);
});

it('switches to a new neighbour version even when the cached version was not forgotten', function () {
    $other = Article::find(5);
    expect(array_column($other->similar(), 'id'))->toBe([6]); // caches the current version

    // A rebuild on a server whose cache this one does not share: the version
    // changes in rec_state, the old rows go, but our cached version stays.
    $cache = app(\SolutionForest\Recommender\Feed\FeedCache::class);
    $feed = Recommender::feed('articles');
    $stale = $cache->neighbourVersion($feed, fn () => null);
    $store = app(\SolutionForest\Recommender\Storage\NeighbourStore::class);
    $next = $store->nextVersion('articles');
    $rows = $store->neighboursOf('articles', [1, 2, 4, 5, 6], $stale);
    $store->write('articles', $next, $rows);
    $store->activate('articles', $next);
    expect($cache->neighbourVersion($feed, fn () => null))->toBe($stale);

    expect(array_column(Article::find(1)->similar(), 'id'))->toBe([2, 4])
        ->and($cache->neighbourVersion($feed, fn () => $store->activeVersion('articles')))->toBe($next);
});

it('reads no table on a warm call for an item without neighbours', function () {
    $lonely = Article::find(8);
    $lonely->similar();

    expect(queriesDuring(fn () => $lonely->similar()))->toBe(0);
});
