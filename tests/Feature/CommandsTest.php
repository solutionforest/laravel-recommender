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

    $this->artisan('recommender:make-feed', ['name' => 'posts', '--force' => true])
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
