<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SolutionForest\Recommender\Examples\EventsAdvanced\Models\Event;
use SolutionForest\Recommender\Examples\EventsAdvanced\Http\RecommendedEventsController;
use SolutionForest\Recommender\Facades\Recommender;

/*
 * examples/events-advanced — district + host + type + comments on top of events.
 */
beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../examples/events-advanced/database/migrations');
    $example = require __DIR__.'/../../examples/events-advanced/config/recommender.php';
    config(['recommender.feeds' => $example['feeds']]);
    $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);

    Route::get('/events/recommended', RecommendedEventsController::class);

    $when = now()->subHours(2);
    $start = now()->addDays(3);

    // type_id: 1=outdoor, 2=workshop, 3=social
    // host 201 = party the user joins most often
    $catalog = [
        ['id' => 1, 'host_id' => 200, 'district_id' => 10, 'type_id' => 1, 'title' => 'Local outdoor', 'published' => true, 'starts_at' => $start],
        ['id' => 2, 'host_id' => 201, 'district_id' => 10, 'type_id' => 2, 'title' => 'Local workshop', 'published' => true, 'starts_at' => $start->copy()->addHour()],
        ['id' => 3, 'host_id' => 202, 'district_id' => 99, 'type_id' => 2, 'title' => 'Far workshop', 'published' => true, 'starts_at' => $start->copy()->addHours(2)],
        ['id' => 4, 'host_id' => 203, 'district_id' => 99, 'type_id' => 3, 'title' => 'Far social (views)', 'published' => true, 'starts_at' => $start->copy()->addHours(3)],
        ['id' => 5, 'host_id' => 204, 'district_id' => 10, 'type_id' => 2, 'title' => 'Draft', 'published' => false, 'starts_at' => $start],
        ['id' => 6, 'host_id' => 205, 'district_id' => 10, 'type_id' => 2, 'title' => 'Past local', 'published' => true, 'starts_at' => now()->subDays(10)],
        ['id' => 7, 'host_id' => 206, 'district_id' => 99, 'type_id' => 3, 'title' => 'Far social (comments)', 'published' => true, 'starts_at' => $start->copy()->addHours(4)],
        ['id' => 50, 'host_id' => 201, 'district_id' => 10, 'type_id' => 2, 'title' => 'Old workshop A', 'published' => true, 'starts_at' => now()->subMonths(2)],
        ['id' => 51, 'host_id' => 201, 'district_id' => 11, 'type_id' => 2, 'title' => 'Old workshop B', 'published' => true, 'starts_at' => now()->subMonths(1)],
        ['id' => 52, 'host_id' => 201, 'district_id' => 12, 'type_id' => 2, 'title' => 'Old workshop C', 'published' => true, 'starts_at' => now()->subWeeks(3)],
        ['id' => 53, 'host_id' => 303, 'district_id' => 10, 'type_id' => 1, 'title' => 'Old outdoor', 'published' => true, 'starts_at' => now()->subWeeks(2)],
    ];

    foreach ($catalog as $row) {
        Event::create([
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

    // User 1: joins workshops hosted by party 201 most often.
    DB::table('event_joins')->insert([
        ['user_id' => 1, 'event_id' => 50, 'created_at' => $when],
        ['user_id' => 1, 'event_id' => 51, 'created_at' => $when],
        ['user_id' => 1, 'event_id' => 52, 'created_at' => $when],
        ['user_id' => 1, 'event_id' => 53, 'created_at' => $when],
    ]);

    // Busy discussion on event 7; many quiet views on event 4.
    $comments = [];
    foreach (range(10, 25) as $u) {
        $comments[] = ['user_id' => $u, 'event_id' => 7, 'body' => 'Looking forward', 'created_at' => $when];
    }
    DB::table('event_comments')->insert($comments);

    $views = [];
    foreach (range(2, 40) as $u) {
        $views[] = ['user_id' => $u, 'event_id' => 4, 'created_at' => $when];
    }
    DB::table('event_views')->insert($views);
    DB::table('event_likes')->insert([
        ['user_id' => 2, 'event_id' => 4, 'created_at' => $when],
    ]);

    $this->artisan('recommender:update-scores', ['feed' => 'events'])->assertSuccessful();
    $this->artisan('recommender:build-neighbours', ['feed' => 'events'])->assertSuccessful();
});

it('ranks same-district + favorite host/type events first for a local workshop fan', function () {
    $response = $this->actingAs(new GenericUser(['id' => 1]))->getJson('/events/recommended');

    $data = collect($response->json('data'));
    $ids = $data->pluck('id');

    $response->assertOk()->assertJsonPath('origin', 'personal');

    expect($ids->first())->toBe(2)
        ->and($ids)->toContain(1)
        ->and($ids)->toContain(3)
        ->and($ids)->not->toContain(5)
        ->and($ids)->not->toContain(6)
        ->and($data->first())->toHaveKeys(['id', 'title', 'host_id', 'district_id', 'type_id', 'comment_count', 'starts_at']);

    if ($ids->contains(4)) {
        expect($ids->search(2))->toBeLessThan($ids->search(4));
    }
});

it('lets comments outweigh many views in trending scores', function () {
    $hot = DB::table('rec_item_scores')
        ->where('feed', 'events')
        ->whereIn('item_id', [4, 7])
        ->pluck('hot', 'item_id');

    expect((float) $hot[7])->toBeGreaterThan((float) $hot[4]);
});

it('explains district, host, type, and comment heat', function () {
    $rows = Recommender::explain('events', 1, 20);
    $labels = collect($rows)->flatMap(fn ($row) => array_keys($row['sources']))->unique()->values();

    expect($labels)->toContain('SameDistrictSource')
        ->and($labels)->toContain('FavoriteTypeSource')
        ->and($labels)->toContain('FavoriteHostSource')
        ->and($labels)->toContain('CommentHeatBooster');
});

it('serves guests without district or host affinity', function () {
    Recommender::forgetTrending('events');
    $response = $this->getJson('/events/recommended');

    $response->assertOk()->assertJsonPath('origin', 'trending');
    expect(collect($response->json('data'))->pluck('id'))
        ->not->toContain(5)
        ->not->toContain(6);
});
