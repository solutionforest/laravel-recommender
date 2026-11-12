<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Examples\Products\Models\Product;
use SolutionForest\Recommender\Examples\Products\Http\RecommendedProductsController;
use SolutionForest\Recommender\Facades\Recommender;

/*
 * examples/products — three events (view / like / purchase).
 */
beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/products/database/migrations');
    $example = require __DIR__.'/../../examples/products/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    Route::get('/products/recommended', RecommendedProductsController::class);

    $when = now()->subHours(2);
    foreach (range(1, 10) as $i) {
        Product::create([
            'id' => $i,
            'seller_id' => 100 + $i,
            'title' => "Product {$i}",
            'published' => $i !== 3,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    // Many views on product 5; few purchases on product 8 → purchase should dominate hot.
    $views = [];
    foreach (range(2, 50) as $u) {
        $views[] = ['user_id' => $u, 'product_id' => 5, 'created_at' => $when];
    }
    DB::table('product_views')->insert($views);

    $purchases = [];
    foreach (range(2, 12) as $u) {
        $purchases[] = ['user_id' => $u, 'product_id' => 8, 'created_at' => $when];
        $purchases[] = ['user_id' => $u, 'product_id' => 9, 'created_at' => $when];
    }
    DB::table('product_purchases')->insert($purchases);

    // Co-purchase cluster for personalisation: users who bought 8 also bought 9.
    $likes = [['user_id' => 1, 'product_id' => 8, 'created_at' => $when]];
    foreach (range(2, 15) as $u) {
        $likes[] = ['user_id' => $u, 'product_id' => 8, 'created_at' => $when];
        $likes[] = ['user_id' => $u, 'product_id' => 9, 'created_at' => $when];
    }
    DB::table('product_likes')->insert($likes);

    $this->artisan('recommender:update-scores', ['feed' => 'products'])->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'products'])->assertSuccessful();
});

it('recommends co-purchased products for a user who liked product 8', function () {
    $response = $this->actingAs(new GenericUser(['id' => 1]))->getJson('/products/recommended');

    $ids = collect($response->json('data'))->pluck('id');

    $response->assertOk()->assertJsonPath('origin', 'personal');
    expect($ids->first())->toBe(9)
        ->and($ids)->not->toContain(3)
        ->and($response->json('data.0'))->toHaveKeys(['id', 'title', 'seller_id', 'published_at']);
});

it('lets purchase outweigh many views in trending scores', function () {
    $hot = DB::table('rec_item_scores')
        ->where('feed', 'products')
        ->whereIn('item_id', [5, 8])
        ->pluck('hot', 'item_id');

    expect((float) $hot[8])->toBeGreaterThan((float) $hot[5]);
});

it('explains product rankings with co-interaction', function () {
    $this->artisan('recommender:explain', ['feed' => 'products', 'user' => 1])
        ->expectsOutputToContain('CoInteractionSource')
        ->assertSuccessful();
});

it('serves guests trending products', function () {
    Recommender::forgetTrending('products');
    $response = $this->getJson('/products/recommended');

    $response->assertOk()->assertJsonPath('origin', 'trending');
    expect(collect($response->json('data'))->pluck('id'))->not->toBeEmpty()->not->toContain(3);
});
