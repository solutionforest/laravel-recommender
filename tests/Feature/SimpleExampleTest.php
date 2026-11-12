<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Examples\Simple\Models\Article;
use SolutionForest\Recommender\Facades\Recommender;

/*
 * examples/simple — a feed configured with only `model` + `interactions`.
 */
beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/simple/database/migrations');
    $example = require __DIR__.'/../../examples/simple/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    Route::get('/articles/for-you', fn (Request $request) => recommendations('articles', $request->user()));

    $when = now()->subHours(2);
    foreach (range(1, 8) as $i) {
        Article::create([
            'id' => $i,
            'author_id' => $i === 7 ? 1 : 100 + $i, // user 1 wrote article 7
            'title' => "Article {$i}",
            'published' => $i !== 3,
        ]);
    }

    // Readers of 1 also like 2 (and 3, unpublished, and 7, user 1's own).
    $likes = [['user_id' => 1, 'article_id' => 1, 'created_at' => $when]];
    foreach (range(2, 10) as $u) {
        foreach ([1, 2, 3, 7] as $article) {
            $likes[] = ['user_id' => $u, 'article_id' => $article, 'created_at' => $when];
        }
    }
    DB::table('article_likes')->insert($likes);

    $views = [];
    foreach (range(2, 30) as $u) {
        $views[] = ['user_id' => $u, 'article_id' => 5, 'created_at' => $when];
    }
    DB::table('article_views')->insert($views);

    $this->artisan('recommender:update-scores', ['feed' => 'articles'])->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'articles'])->assertSuccessful();
});

it('recommends similar articles with no custom classes', function () {
    $response = $this->actingAs(new GenericUser(['id' => 1]))->getJson('/articles/for-you');

    $ids = collect($response->json('data'))->pluck('id');

    $response->assertOk()
        ->assertJsonPath('origin', 'personal')
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('data.0', ['id' => 2, 'title' => 'Article 2', 'author_id' => 102]);

    expect($ids)->not->toContain(3) // unpublished (recommendable scope)
        ->and($ids)->not->toContain(7) // the viewer's own article
        ->and($response->json('path'))->toEndWith('/articles/for-you');
});

it('reads the page number from the request and never repeats items', function () {
    $user = new GenericUser(['id' => 1]);
    config(['recommender.feeds.articles.page_size' => 2]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    $first = $this->actingAs($user)->getJson('/articles/for-you')->json('data');
    $second = $this->actingAs($user)->getJson('/articles/for-you?page=2');

    $second->assertJsonPath('current_page', 2);
    expect(collect($second->json('data'))->pluck('id')->intersect(collect($first)->pluck('id')))->toBeEmpty();
});

it('accepts the user model and passes it to sources as an attribute', function () {
    $page = Recommender::page('articles', new GenericUser(['id' => 1]));

    expect($page->origin)->toBe('personal')
        ->and($page->ids)->toContain(2)
        ->and($page->ids)->not->toContain(7);
});

it('serves guests trending articles filtered by the scope', function () {
    $response = $this->getJson('/articles/for-you');

    $response->assertOk()->assertJsonPath('origin', 'trending');
    expect(collect($response->json('data'))->pluck('id'))->toContain(5)->not->toContain(3);
});

it('limits items per author with diversity keys read from the model', function () {
    // Trending order is 1, 2, 7, 5 — make the top three share an author.
    Article::query()->whereIn('id', [1, 2, 7])->update(['author_id' => 500]);
    config(['recommender.feeds.articles.page_size' => 2]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    expect(Recommender::page('articles')->ids)->toBe([1, 5]);
});

it('refreshes a cached card when the model is saved', function () {
    $this->getJson('/articles/for-you'); // caches trending cards
    Article::find(5)->update(['title' => 'Renamed']);
    Recommender::forgetTrending('articles');

    $titles = collect($this->getJson('/articles/for-you')->json('data'))->pluck('title', 'id');

    expect($titles[5])->toBe('Renamed');
});

it('passes recommender:doctor with the config-only feed', function () {
    $this->artisan('recommender:doctor', ['feed' => 'articles'])->assertSuccessful();
});

it('drops an item from already-cached lists as soon as it is unpublished', function () {
    $user = new GenericUser(['id' => 1]);
    config(['recommender.feeds.articles.page_size' => 1]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    expect(Recommender::page('articles', $user, 1)->ids)->toBe([2]); // list + card for 2 now cached
    Recommender::forgetUser('articles', $user);                       // forget seen: 2 would be served again
    Recommender::build('articles', $user);                            // cached list still starts with 2

    // Unpublish while the list (and the card) are cached. The Recommendable
    // trait forgets the card, so the next render re-checks the scope.
    Article::find(2)->update(['published' => false]);

    $ids = collect(range(1, 5))->flatMap(fn ($p) => Recommender::page('articles', $user, $p)->ids);
    expect($ids)->not->toContain(2)
        ->and(Recommender::page('articles', null)->ids)->not->toContain(2); // trending too
});
