<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Examples\Events\Event;
use SolutionForest\Recommender\Examples\Events\RecommendedEventsController;
use SolutionForest\Recommender\Facades\Recommender;

/*
 * examples/events — view / like / join → recommended events listing.
 */
beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/events/database/migrations');
    $example = require __DIR__.'/../../examples/events/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    Route::get('/events/recommended', RecommendedEventsController::class);

    $when = now()->subHours(2);
    $start = now()->addDays(3);

    foreach (range(1, 10) as $i) {
        Event::create([
            'id' => $i,
            'host_id' => 100 + $i,
            'title' => "Meetup {$i}",
            'published' => $i !== 3,
            'starts_at' => $i === 4 ? now()->subDays(10) : $start->copy()->addHours($i), // 4 = past → filtered
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    // Many views on 5; joins on 8+9 cluster for CF.
    $views = [];
    foreach (range(2, 40) as $u) {
        $views[] = ['user_id' => $u, 'event_id' => 5, 'created_at' => $when];
    }
    DB::table('event_views')->insert($views);

    $joins = [];
    foreach (range(2, 12) as $u) {
        $joins[] = ['user_id' => $u, 'event_id' => 8, 'created_at' => $when];
        $joins[] = ['user_id' => $u, 'event_id' => 9, 'created_at' => $when];
    }
    DB::table('event_joins')->insert($joins);

    $likes = [['user_id' => 1, 'event_id' => 8, 'created_at' => $when]];
    foreach (range(2, 15) as $u) {
        $likes[] = ['user_id' => $u, 'event_id' => 8, 'created_at' => $when];
        $likes[] = ['user_id' => $u, 'event_id' => 9, 'created_at' => $when];
    }
    DB::table('event_likes')->insert($likes);

    $this->artisan('recommender:update-scores', ['feed' => 'events'])->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'events'])->assertSuccessful();
});

it('recommends co-joined events for a user who liked event 8', function () {
    $response = $this->actingAs(new GenericUser(['id' => 1]))->getJson('/events/recommended');

    $ids = collect($response->json('data'))->pluck('id');

    $response->assertOk()->assertJsonPath('origin', 'personal');
    expect($ids->first())->toBe(9)
        ->and($ids)->not->toContain(3)  // unpublished
        ->and($ids)->not->toContain(4)  // past
        ->and($response->json('data.0'))->toHaveKeys(['id', 'title', 'host_id', 'starts_at']);
});

it('lets join outweigh many views in trending scores', function () {
    $hot = DB::table('rec_item_scores')
        ->where('feed', 'events')
        ->whereIn('item_id', [5, 8])
        ->pluck('hot', 'item_id');

    expect((float) $hot[8])->toBeGreaterThan((float) $hot[5]);
});

it('explains event rankings', function () {
    $this->artisan('recommender:explain', ['feed' => 'events', 'user' => 1])
        ->expectsOutputToContain('CoInteractionSource')
        ->assertSuccessful();
});

it('serves guests a trending events listing', function () {
    Recommender::forgetTrending('events');
    $response = $this->getJson('/events/recommended');

    $response->assertOk()->assertJsonPath('origin', 'trending');
    expect(collect($response->json('data'))->pluck('id'))
        ->not->toBeEmpty()
        ->not->toContain(3)
        ->not->toContain(4);
});
