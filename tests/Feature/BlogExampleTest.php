<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Examples\Blog\Models\Post;
use SolutionForest\Recommender\Examples\Blog\Http\RecommendedPostsController;

/*
 * Runs the sample in examples/blog end to end, so the documentation can't rot:
 * seed -> update-scores -> build-neighbours -> GET /posts/recommended.
 */
beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/blog/database/migrations');
    $example = require __DIR__.'/../../examples/blog/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    Route::get('/posts/recommended', RecommendedPostsController::class);

    $when = now()->subHours(2);
    foreach (range(1, 12) as $i) {
        Post::create([
            'id' => $i,
            'author_id' => match ($i) { 4 => 1, default => 100 + $i },   // post 4 is written by user 1
            'title' => "Post {$i}",
            'published' => $i !== 3,                                    // post 3 is unpublished
            'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    // Users 2..20 like posts 1 and 2 together; user 1 only liked post 1 so far.
    $likes = [];
    foreach (range(2, 20) as $u) {
        $likes[] = ['user_id' => $u, 'post_id' => 1, 'created_at' => $when];
        $likes[] = ['user_id' => $u, 'post_id' => 2, 'created_at' => $when];
    }
    $likes[] = ['user_id' => 1, 'post_id' => 1, 'created_at' => $when];
    DB::table('post_likes')->insert($likes);   // bulk, like the package does

    $views = [];
    foreach (range(2, 40) as $u) {
        foreach ([5, 6, 7, 3] as $post) {
            $views[] = ['user_id' => $u, 'post_id' => $post, 'created_at' => $when];
        }
    }
    DB::table('post_views')->insert($views);

    $this->artisan('recommender:update-scores')->assertSuccessful();
    $this->artisan('recommender:build-neighbours')->assertSuccessful();
});

it('recommends what similar users liked, without own or unpublished posts', function () {
    $response = $this->actingAs(new GenericUser(['id' => 1]))->getJson('/posts/recommended');

    $ids = collect($response->json('data'))->pluck('id');

    $response->assertOk()->assertJsonPath('origin', 'personal');
    expect($ids->first())->toBe(2)                 // liked together with post 1
        ->and($ids)->not->toContain(3)             // unpublished
        ->and($ids)->not->toContain(4)             // user 1's own post
        ->and($response->json('data.0'))->toHaveKeys(['id', 'title', 'author_id', 'published_at']);
});

it('pages without repeating posts', function () {
    $user = new GenericUser(['id' => 1]);
    config(['recommender.feeds.posts.page_size' => 4]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    $p1 = collect($this->actingAs($user)->getJson('/posts/recommended?page=1')->json('data'))->pluck('id');
    $p2 = collect($this->actingAs($user)->getJson('/posts/recommended?page=2')->json('data'))->pluck('id');

    expect($p1)->toHaveCount(4)
        ->and($p2)->not->toBeEmpty()
        ->and($p1->intersect($p2))->toBeEmpty();
});

it('serves guests the shared trending list', function () {
    $response = $this->getJson('/posts/recommended');

    $response->assertOk()->assertJsonPath('origin', 'trending');
    expect(collect($response->json('data'))->pluck('id'))->not->toBeEmpty()->not->toContain(3);
});

it('explains a ranking', function () {
    $this->artisan('recommender:explain', ['feed' => 'posts', 'user' => 1])
        ->expectsOutputToContain('CoInteractionSource')
        ->assertSuccessful();
});

it('surfaces sponsored author posts via source and booster', function () {
    // Author 112 owns post 12. Cold user 99 has no likes — pool comes from
    // trending + sponsored injection, then booster lifts sponsored items.
    config([
        'recommender.feeds.posts.sponsored_author_ids' => [112],
        'recommender.feeds.posts.sources' => [
            \SolutionForest\Recommender\Sources\CoInteractionSource::class => 0.05,
            \SolutionForest\Recommender\Sources\TrendingSource::class => 0.15,
            \SolutionForest\Recommender\Examples\Blog\Sources\SponsoredAuthorSource::class => 0.80,
        ],
        'recommender.feeds.posts.boosters' => [
            \SolutionForest\Recommender\Boosters\QualityBooster::class => 0.05,
            \SolutionForest\Recommender\Examples\Blog\Boosters\SponsoredAuthorBooster::class => 0.90,
        ],
        'recommender.feeds.posts.exploration_ratio' => 0,
    ]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    $response = $this->actingAs(new GenericUser(['id' => 99]))->getJson('/posts/recommended');
    $ids = collect($response->json('data'))->pluck('id');

    $response->assertOk()->assertJsonPath('origin', 'personal');
    expect($ids->first())->toBe(12)
        ->and($ids)->toContain(12);

    $this->artisan('recommender:explain', ['feed' => 'posts', 'user' => 99, '--limit' => 5])
        ->expectsOutputToContain('SponsoredAuthorSource')
        ->assertSuccessful();
});

it('passes recommender:doctor with the config-only feed', function () {
    $this->artisan('recommender:doctor', ['feed' => 'posts'])->assertSuccessful();
});
