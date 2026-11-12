<?php

use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Examples\Products\Models\Product;
use SolutionForest\Recommender\Recommender;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/products/database/migrations');
    app(\Illuminate\Filesystem\Filesystem::class)->delete(config_path('recommender.php'));
    config(['recommender.feeds' => []]);
    $this->app->forgetInstance(Recommender::class);

    foreach (range(1, 6) as $i) {
        Product::create(['id' => $i, 'seller_id' => 100 + $i, 'title' => "Product {$i}", 'published' => true]);
    }
    $likes = [];
    foreach (range(1, 8) as $u) {
        foreach ([1, 2] as $p) {
            $likes[] = ['user_id' => $u, 'product_id' => $p, 'created_at' => now()->subHours(3)];
        }
    }
    DB::table('product_likes')->insert($likes);
    DB::table('product_views')->insert(['user_id' => 9, 'product_id' => 3, 'created_at' => now()->subHours(3)]);
});

function bootstrapArgs(array $extra = []): array
{
    return array_replace([
        'name' => 'shop',
        '--preset' => 'products',
        '--model' => Product::class,
        '--likes' => 'product_likes',
        '--views' => 'product_views',
        '--purchases' => 'product_purchases',
        '--item-column' => 'product_id',
        '--no-interaction' => true,
    ], $extra);
}

it('sets up a working feed in one command', function () {
    $this->artisan('recommender:bootstrap', bootstrapArgs())
        ->expectsOutputToContain('Registered feed [shop]')
        ->expectsOutputToContain('Feed [shop] is ready.')
        ->expectsOutputToContain('Product::recommendedFor($r->user())')
        ->assertSuccessful();

    $feed = (require config_path('recommender.php'))['feeds']['shop'];
    expect($feed['preset'])->toBe('catalog')
        ->and(DB::table('rec_item_scores')->where('feed', 'shop')->count())->toBe(3)
        ->and(app(Recommender::class)->page('shop', 1)->ids)->not->toBeEmpty();
});

it('runs migrations first unless skipped', function () {
    $this->artisan('recommender:bootstrap', bootstrapArgs(['--skip-migrate' => true]))->assertSuccessful();
    $this->artisan('recommender:bootstrap', bootstrapArgs(['name' => 'shop2']))->expectsOutputToContain('Migrate')->assertSuccessful();
});

it('fails with the doctor output when the schema does not match', function () {
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    $code = \Illuminate\Support\Facades\Artisan::call('recommender:bootstrap', bootstrapArgs(['--likes' => 'missing_likes', '--skip-migrate' => true]), $output);
    $text = $output->fetch();

    expect($code)->toBe(1)
        ->and($text)->toContain('interactions table [missing_likes] does not exist')
        ->and($text)->toContain('fix the checks above');
});
