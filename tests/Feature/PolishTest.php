<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Recommender as RecommenderService;
use SolutionForest\Recommender\Support\Context;
use SolutionForest\Recommender\Support\Cursor;
use SolutionForest\Recommender\Support\Ids;

class LocalizedArticle extends Article
{
    protected $table = 'articles';

    public function toRecommendationCard(?Context $context = null): array
    {
        return ['id' => $this->id, 'title' => strtoupper((string) $context?->attribute('locale')).' '.$this->title];
    }
}

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    config(['recommender.feeds' => [
        'articles' => ['model' => Article::class, 'interactions' => ['article_likes' => 'like'], 'exploration_ratio' => 0, 'page_size' => 2],
        'other' => ['model' => LocalizedArticle::class, 'interactions' => ['article_likes' => 'like'], 'item_column' => 'article_id', 'exploration_ratio' => 0, 'page_size' => 2],
    ]]);
    $this->app->forgetInstance(RecommenderService::class);

    foreach (range(1, 5) as $i) {
        Article::create(['id' => $i, 'author_id' => 100 + $i, 'title' => "Article {$i}"]);
    }
    $likes = [];
    foreach (range(1, 6) as $u) {
        foreach ([3, 1, 4] as $n => $a) {
            if ($u > $n) {
                $likes[] = ['user_id' => $u, 'article_id' => $a, 'created_at' => now()->subHours(2)];
            }
        }
    }
    DB::table('article_likes')->insert($likes);
    $this->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();
});

it('keeps zero-padded ids as strings and plain numbers as integers', function () {
    expect(Ids::normalize('007'))->toBe('007')
        ->and(Ids::normalize('000123'))->toBe('000123')
        ->and(Ids::normalize('123'))->toBe(123)
        ->and(Ids::normalize('0'))->toBe(0)
        ->and(FeedCache::unpack(FeedCache::pack(['007', 42, '0'])))->toBe(['007', 42, 0]);
});

it('returns the page as Eloquent models in ranked order', function () {
    $page = Recommender::page('articles', null);
    $models = $page->models();

    expect($models->modelKeys())->toBe($page->ids)
        ->and($models->first())->toBeInstanceOf(Article::class)
        ->and(Article::recommendedFor(null, 'articles')->models())->toHaveCount(2);
});

it('explains when models() cannot work', function () {
    $page = new \SolutionForest\Recommender\Support\FeedPage([], [1], 1, 1, false, 0, 'trending');

    expect(fn () => $page->models())->toThrow(LogicException::class, 'needs a feed with a model');
});

it('binds cursors to their feed', function () {
    $next = Recommender::page('articles', null)->nextCursor();

    expect(Cursor::decode($next, 'articles'))->toBe(2)
        ->and(Cursor::decode($next, 'other'))->toBeNull()                 // another feed starts at page 1
        ->and(Cursor::decode(Cursor::encode(3), 'articles'))->toBe(3);   // pre-0.6.2 tokens still work

    $this->get('/?cursor='.$next); // bind a request carrying the cursor
    expect(Recommender::page('other', null)->page)->toBe(1);
});

it('caches cards per locale when cards_vary_by is set, and forgets every variant at once', function () {
    config(['recommender.feeds.other.cards_vary_by' => ['locale']]);
    $this->app->forgetInstance(RecommenderService::class);
    $this->artisan('recommender:update-scores', ['feed' => 'other'])->assertSuccessful();

    app()->setLocale('en');
    $en = Recommender::page('other', null)->items[0]['title'];
    app()->setLocale('fr');
    $fr = Recommender::page('other', null)->items[0]['title'];
    $explicit = Recommender::page('other', null, attributes: ['locale' => 'de'])->items[0]['title'];

    expect($en)->toStartWith('EN ')->and($fr)->toStartWith('FR ')->and($explicit)->toStartWith('DE ');

    LocalizedArticle::find((int) substr($en, -1))->update(['title' => 'Renamed']); // Recommendable forgets all variants
    app()->setLocale('en');
    expect(Recommender::page('other', null)->items[0]['title'])->toBe('EN Renamed');
});

it('shares one card for everyone by default', function () {
    $this->artisan('recommender:update-scores', ['feed' => 'other'])->assertSuccessful();

    app()->setLocale('en');
    $first = Recommender::page('other', null)->items[0]['title'];
    app()->setLocale('fr');

    expect(Recommender::page('other', null)->items[0]['title'])->toBe($first);
});
