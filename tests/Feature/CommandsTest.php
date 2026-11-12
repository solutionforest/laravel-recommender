<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Jobs\BuildFeed;
use SolutionForest\Recommender\Tests\Fixtures\ArrayInteractions;

it('runs the maintenance commands', function () {
    foreach ([1, 2] as $u) {
        ArrayInteractions::add($u, 10, 'like', now()->subDay()->toDateTimeString());
        ArrayInteractions::add($u, 11, 'view', now()->subDay()->toDateTimeString());
    }

    $this->artisan('recommender:update-scores')->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'reviews'])->assertSuccessful();
    $this->artisan('recommender:explain', ['feed' => 'reviews', 'user' => 1])->assertSuccessful();
    $this->artisan('recommender:status', ['feed' => 'reviews'])
        ->expectsOutputToContain('Feed [reviews]')
        ->expectsOutputToContain('scores watermark')
        ->assertSuccessful();

    expect(DB::table('rec_item_scores')->count())->toBe(2)
        ->and(DB::table('rec_item_neighbours')->count())->toBe(2);
});

it('queues a unique warm-up build', function () {
    Bus::fake();

    Recommender::warm('reviews', 7);

    Bus::assertDispatched(BuildFeed::class, fn (BuildFeed $job) => $job->uniqueId() === 'reviews:7' && $job->queue === 'recommender');
});

it('scaffolds a basic feed with recommender:make-feed', function () {
    $files = app(\Illuminate\Filesystem\Filesystem::class);
    $files->deleteDirectory(app_path('Recommendation/Posts'));
    $files->delete(config_path('recommender.php'));

    $this->artisan('recommender:make-feed', [
        'name' => 'posts',
        '--force' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Created:')
        ->expectsOutputToContain('Registered feed [posts]')
        ->assertSuccessful();

    expect(file_exists(app_path('Recommendation/Posts/PostInteractionSource.php')))->toBeTrue()
        ->and(file_exists(app_path('Recommendation/Posts/VisiblePostFilter.php')))->toBeTrue()
        ->and(file_exists(app_path('Recommendation/Posts/PostCardTransformer.php')))->toBeTrue()
        ->and(file_exists(config_path('recommender.php')))->toBeTrue();

    $config = file_get_contents(config_path('recommender.php'));
    expect($config)->toContain("'posts'")
        ->and($config)->toContain('PostInteractionSource::class')
        ->and($config)->toContain('VisiblePostFilter::class')
        ->and($config)->toContain('PostCardTransformer::class');

    $interaction = file_get_contents(app_path('Recommendation/Posts/PostInteractionSource.php'));
    expect($interaction)->toContain('post_likes')
        ->and($interaction)->toContain('post_views')
        ->and($interaction)->toContain('implements InteractionSource');
});

it('scaffolds a products preset with a third purchase event', function () {
    $files = app(\Illuminate\Filesystem\Filesystem::class);
    $files->deleteDirectory(app_path('Recommendation/Products'));
    $files->delete(config_path('recommender.php'));

    $this->artisan('recommender:make-feed', [
        'name' => 'products',
        '--preset' => 'products',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $interaction = file_get_contents(app_path('Recommendation/Products/ProductInteractionSource.php'));
    $config = file_get_contents(config_path('recommender.php'));

    expect($interaction)->toContain('product_likes')
        ->and($interaction)->toContain('product_views')
        ->and($interaction)->toContain('product_purchases')
        ->and($interaction)->toContain("'purchase'")
        ->and($config)->toContain("'purchase'")
        ->and($config)->toContain('10.0');
});

it('walks the interactive scaffold wizard', function () {
    $files = app(\Illuminate\Filesystem\Filesystem::class);
    $files->deleteDirectory(app_path('Recommendation/Posts'));
    $files->delete(config_path('recommender.php'));

    $presets = [
        'blog — posts with like + view',
        'products — catalog with like + view + purchase',
        'reviews — UGC with like + view',
        'custom — answer every prompt',
    ];

    $this->artisan('recommender:make-feed', ['--force' => true])
        ->expectsChoice('Choose a starting preset', $presets[0], $presets)
        ->expectsQuestion('Feed name (snake_case)', 'posts')
        ->expectsQuestion('Eloquent model (FQCN or short name)', 'App\\Models\\Post')
        ->expectsQuestion('Likes table', 'post_likes')
        ->expectsQuestion('Views table', 'post_views')
        ->expectsQuestion('Item FK column on interaction tables', 'post_id')
        ->expectsQuestion('Owner column on item (hide own)', 'author_id')
        ->expectsQuestion('Published column on item', 'published')
        ->expectsQuestion('Title column on item', 'title')
        ->expectsConfirmation('Add a third interaction type (purchase / share / bookmark / …)?', 'no')
        ->expectsConfirmation('Create these files and register the feed?', 'yes')
        ->expectsOutputToContain('Registered feed [posts]')
        ->assertSuccessful();
});
