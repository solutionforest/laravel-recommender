<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Support\Context;

/** An article whose recommendable scope reads a `tenant` attribute (docs/advanced.md). */
class TenantScopedArticle extends Article
{
    protected $table = 'articles';

    public function getForeignKey()
    {
        return 'article_id';
    }

    public function scopeRecommendable(Builder $query, Context $context): void
    {
        $query->where('published', true)->where('author_id', $context->attribute('tenant'));
    }
}

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    $example['feeds']['articles']['model'] = TenantScopedArticle::class;
    $example['feeds']['articles']['list_vary_by'] = ['tenant'];
    $example['feeds']['articles']['item_column'] = 'article_id';
    $example['feeds']['articles']['diversity'] = [];
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    // author_id doubles as the tenant: articles 1, 2 -> tenant 1; 3, 4 -> tenant 2.
    foreach ([1 => 1, 2 => 1, 3 => 2, 4 => 2] as $id => $tenant) {
        Article::create(['id' => $id, 'author_id' => $tenant, 'title' => "Article {$id}", 'published' => true]);
    }
    // The same users like items of both tenants, so neighbours cross tenants.
    $likes = [];
    foreach (range(1, 12) as $user) {
        foreach ([1, 2, 3, 4] as $article) {
            $likes[] = ['user_id' => $user, 'article_id' => $article, 'created_at' => now()->subHours(2)];
        }
    }
    DB::table('article_likes')->insert($likes);
    $this->artisan('recommender:build-neighbours', ['feed' => 'articles'])->assertSuccessful();
});

it('never shows another tenant\'s item, even when its card is already cached', function () {
    expect(array_column(Recommender::similar('articles', 1, 6, ['tenant' => 1]), 'id'))->toBe([2]);

    // Tenant 2 renders (and caches) the cards of its own items 3 and 4.
    expect(array_column(Recommender::similar('articles', 3, 6, ['tenant' => 2]), 'id'))->toBe([4]);

    // Tenant 1 asks about another of its items: still only its own.
    expect(array_column(Recommender::similar('articles', 2, 6, ['tenant' => 1]), 'id'))->toBe([1])
        ->and(array_column(Recommender::similar('articles', 4, 6, ['tenant' => 2]), 'id'))->toBe([3]);
});

it('keeps an empty list for unknown ids only briefly', function () {
    config(['recommender.cache_store' => 'array']);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);
    $this->app->forgetInstance(FeedCache::class);

    $this->travelTo(now());
    expect(Recommender::similar('articles', 'unknown-1', 6, ['tenant' => 1]))->toBe([])
        ->and(Recommender::similar('articles', 1, 6, ['tenant' => 1]))->not->toBe([]);

    $this->travel(FeedCache::SIMILAR_EMPTY_TTL + 1)->seconds();
    $storage = (new ReflectionProperty($store = cache()->store('array')->getStore(), 'storage'))->getValue($store);
    $live = fn (string $needle) => array_filter($storage, fn ($entry, $key) => str_contains($key, $needle) && ($entry['expiresAt'] === 0 || $entry['expiresAt'] > now()->getPreciseTimestamp(3) / 1000), ARRAY_FILTER_USE_BOTH);

    expect($live('similar:unknown-1'))->toBe([])
        ->and($live('similar:1:'))->not->toBe([]);
});
