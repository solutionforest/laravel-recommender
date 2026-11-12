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

    expect(DB::table('rec_item_scores')->count())->toBe(2)
        ->and(DB::table('rec_item_neighbours')->count())->toBe(2);
});

it('queues a unique warm-up build', function () {
    Bus::fake();

    Recommender::warm('reviews', 7);

    Bus::assertDispatched(BuildFeed::class, fn (BuildFeed $job) => $job->uniqueId() === 'reviews:7' && $job->queue === 'recommender');
});
