<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Examples\EventsAdvanced\MeetupEvent;
use SolutionForest\Recommender\Examples\EventsAdvanced\RecommendedMeetupEventsController;
use SolutionForest\Recommender\Facades\Recommender;

/*
 * examples/events-advanced — district + favorite type on top of view/like/join.
 */
beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/events-advanced/database/migrations');
    $example = require __DIR__.'/../../examples/events-advanced/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    Route::get('/meetup-events/recommended', RecommendedMeetupEventsController::class);

    $when = now()->subHours(2);
    $start = now()->addDays(3);

    // type_id: 1=outdoor, 2=workshop, 3=social
    $catalog = [
        // Upcoming candidates
        ['id' => 1, 'host_id' => 200, 'district_id' => 10, 'type_id' => 1, 'title' => 'Local outdoor', 'published' => true, 'starts_at' => $start],
        ['id' => 2, 'host_id' => 201, 'district_id' => 10, 'type_id' => 2, 'title' => 'Local workshop', 'published' => true, 'starts_at' => $start->copy()->addHour()],
        ['id' => 3, 'host_id' => 202, 'district_id' => 99, 'type_id' => 2, 'title' => 'Far workshop', 'published' => true, 'starts_at' => $start->copy()->addHours(2)],
        ['id' => 4, 'host_id' => 203, 'district_id' => 99, 'type_id' => 3, 'title' => 'Far social', 'published' => true, 'starts_at' => $start->copy()->addHours(3)],
        ['id' => 5, 'host_id' => 204, 'district_id' => 10, 'type_id' => 2, 'title' => 'Draft', 'published' => false, 'starts_at' => $start],
        ['id' => 6, 'host_id' => 205, 'district_id' => 10, 'type_id' => 2, 'title' => 'Past local', 'published' => true, 'starts_at' => now()->subDays(10)],
        // Historical workshops user 1 joined (type affinity = workshop)
        ['id' => 50, 'host_id' => 300, 'district_id' => 10, 'type_id' => 2, 'title' => 'Old workshop A', 'published' => true, 'starts_at' => now()->subMonths(2)],
        ['id' => 51, 'host_id' => 301, 'district_id' => 11, 'type_id' => 2, 'title' => 'Old workshop B', 'published' => true, 'starts_at' => now()->subMonths(1)],
        ['id' => 52, 'host_id' => 302, 'district_id' => 12, 'type_id' => 2, 'title' => 'Old workshop C', 'published' => true, 'starts_at' => now()->subWeeks(3)],
        ['id' => 53, 'host_id' => 303, 'district_id' => 10, 'type_id' => 1, 'title' => 'Old outdoor', 'published' => true, 'starts_at' => now()->subWeeks(2)],
    ];

    foreach ($catalog as $row) {
        MeetupEvent::create([
            ...$row,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    DB::table('user_districts')->insert([
        'user_id' => 1,
        'district_id' => 10,
        'created_at' => $when,
        'updated_at' => $when,
    ]);

    // User 1 joins workshops most often (3× type 2, 1× type 1).
    DB::table('meetup_event_joins')->insert([
        ['user_id' => 1, 'event_id' => 50, 'created_at' => $when],
        ['user_id' => 1, 'event_id' => 51, 'created_at' => $when],
        ['user_id' => 1, 'event_id' => 52, 'created_at' => $when],
        ['user_id' => 1, 'event_id' => 53, 'created_at' => $when],
    ]);

    // Sparse CF noise so district/type sources can dominate.
    DB::table('meetup_event_views')->insert([
        ['user_id' => 2, 'event_id' => 4, 'created_at' => $when],
        ['user_id' => 3, 'event_id' => 4, 'created_at' => $when],
    ]);
    DB::table('meetup_event_likes')->insert([
        ['user_id' => 2, 'event_id' => 4, 'created_at' => $when],
    ]);

    $this->artisan('recommender:update-scores', ['feed' => 'meetup_events'])->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'meetup_events'])->assertSuccessful();
});

it('ranks same-district + favorite-type events first for a local workshop fan', function () {
    $response = $this->actingAs(new GenericUser(['id' => 1]))->getJson('/meetup-events/recommended');

    $data = collect($response->json('data'));
    $ids = $data->pluck('id');

    $response->assertOk()->assertJsonPath('origin', 'personal');

    // Local workshop (district 10 + type 2) should beat far social (neither signal).
    expect($ids->first())->toBe(2)
        ->and($ids)->toContain(1) // same district
        ->and($ids)->toContain(3) // favorite type
        ->and($ids)->not->toContain(5) // unpublished
        ->and($ids)->not->toContain(6) // past
        ->and($data->first())->toHaveKeys(['id', 'title', 'host_id', 'district_id', 'type_id', 'starts_at']);

    // Far social should not outrank local workshop when both appear.
    if ($ids->contains(4)) {
        expect($ids->search(2))->toBeLessThan($ids->search(4));
    }
});

it('explains district and favorite-type sources', function () {
    $this->artisan('recommender:explain', ['feed' => 'meetup_events', 'user' => 1])
        ->expectsOutputToContain('SameDistrictSource')
        ->expectsOutputToContain('FavoriteTypeSource')
        ->assertSuccessful();
});

it('serves guests without district or type affinity', function () {
    Recommender::forgetTrending('meetup_events');
    $response = $this->getJson('/meetup-events/recommended');

    $response->assertOk()->assertJsonPath('origin', 'trending');
    expect(collect($response->json('data'))->pluck('id'))
        ->not->toContain(5)
        ->not->toContain(6);
});
