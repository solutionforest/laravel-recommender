<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Examples\Reviews\Http\RecommendedReviewsController;
use SolutionForest\Recommender\Examples\Reviews\Models\Review;

/*
 * examples/reviews — custom PetTypeMatchSource + shop diversity.
 */
beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/reviews/database/migrations');
    $example = require __DIR__.'/../../examples/reviews/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    Route::get('/reviews/recommended', RecommendedReviewsController::class);

    $when = now()->subHours(2);

    DB::table('shops')->insert([
        ['id' => 1, 'name' => 'Dog Cafe', 'is_opening' => true, 'created_at' => $when, 'updated_at' => $when],
        ['id' => 2, 'name' => 'Cat Cafe', 'is_opening' => true, 'created_at' => $when, 'updated_at' => $when],
        ['id' => 3, 'name' => 'Closed', 'is_opening' => false, 'created_at' => $when, 'updated_at' => $when],
    ]);

    // Writers: 10 = dog owner, 20 = cat owner. Viewer 1 = dog.
    DB::table('user_pets')->insert([
        ['user_id' => 1, 'pet_type_id' => 1, 'created_at' => $when, 'updated_at' => $when],
        ['user_id' => 10, 'pet_type_id' => 1, 'created_at' => $when, 'updated_at' => $when],
        ['user_id' => 20, 'pet_type_id' => 2, 'created_at' => $when, 'updated_at' => $when],
    ]);

    foreach ([
        ['id' => 100, 'user_id' => 10, 'shop_id' => 1, 'title' => 'Dog park day', 'cover_url' => 'https://example.test/d.jpg'],
        ['id' => 101, 'user_id' => 10, 'shop_id' => 1, 'title' => 'Dog brunch', 'cover_url' => 'https://example.test/d2.jpg'],
        ['id' => 200, 'user_id' => 20, 'shop_id' => 2, 'title' => 'Cat nap', 'cover_url' => 'https://example.test/c.jpg'],
        ['id' => 201, 'user_id' => 20, 'shop_id' => 2, 'title' => 'Cat toys', 'cover_url' => 'https://example.test/c2.jpg'],
        ['id' => 300, 'user_id' => 10, 'shop_id' => 3, 'title' => 'Closed shop', 'cover_url' => 'https://example.test/x.jpg'],
        ['id' => 301, 'user_id' => 10, 'shop_id' => 1, 'title' => 'No cover', 'cover_url' => null],
    ] as $row) {
        Review::create([
            ...$row,
            'published' => true,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    // Sparse likes so PetTypeMatch can dominate over co-interaction for cold-ish viewer.
    DB::table('review_likes')->insert([
        ['user_id' => 2, 'review_id' => 200, 'created_at' => $when],
        ['user_id' => 3, 'review_id' => 200, 'created_at' => $when],
    ]);
    DB::table('review_views')->insert([
        ['user_id' => 2, 'review_id' => 200, 'created_at' => $when],
        ['user_id' => 2, 'review_id' => 100, 'created_at' => $when],
    ]);

    $this->artisan('recommender:update-scores', ['feed' => 'reviews'])->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'reviews'])->assertSuccessful();
});

it('surfaces same-pet-type reviews for a dog owner via PetTypeMatchSource', function () {
    $response = $this->actingAs(new GenericUser(['id' => 1]))->getJson('/reviews/recommended');

    $ids = collect($response->json('data'))->pluck('id');

    $response->assertOk()->assertJsonPath('origin', 'personal');
    expect($ids->first())->toBeIn([100, 101]) // PetTypeMatch (dog writers) ranks ahead of cat-only noise
        ->and($ids)->toContain(100)
        ->and($ids)->toContain(101)
        ->and($ids)->not->toContain(300)
        ->and($ids)->not->toContain(301)
        ->and($response->json('data.0'))->toHaveKeys(['id', 'title', 'shop_id', 'writer_id', 'cover_url']);
});

it('explains pet-type contribution', function () {
    $this->artisan('recommender:explain', ['feed' => 'reviews', 'user' => 1])
        ->expectsOutputToContain('PetTypeMatchSource')
        ->assertSuccessful();
});

it('serves guests without pet match', function () {
    $response = $this->getJson('/reviews/recommended');

    $response->assertOk()->assertJsonPath('origin', 'trending');
    expect(collect($response->json('data'))->pluck('id'))->not->toContain(300);
});
