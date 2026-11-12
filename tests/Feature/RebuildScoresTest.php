<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Maintenance\ScoreRebuilder;
use SolutionForest\Recommender\Maintenance\ScoreUpdater;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Storage\StateStore;
use SolutionForest\Recommender\Tests\Fixtures\ArrayInteractions;

beforeEach(function () {
    // Two feeds share rec_item_scores; only one is rebuilt.
    $feeds = config('recommender.feeds');
    $feeds['other'] = $feeds['reviews'];
    config(['recommender.feeds' => $feeds]);
    $this->app->forgetInstance(Recommender::class);

    $this->now = CarbonImmutable::now();
    // Old history (60 days ago) that the first 30-day run never saw, plus recent events.
    ArrayInteractions::add(1, 10, 'like', $this->now->subDays(60)->toDateTimeString());
    ArrayInteractions::add(2, 10, 'like', $this->now->subDays(55)->toDateTimeString());
    ArrayInteractions::add(3, 10, 'like', $this->now->subDays(2)->toDateTimeString());
    ArrayInteractions::add(3, 11, 'view', $this->now->subDays(1)->toDateTimeString());

    $this->artisan('recommender:update-scores')->assertSuccessful(); // both feeds, 30-day window
});

function positives(string $feed, string $item = '10'): int
{
    return (int) DB::table('rec_item_scores')->where('feed', $feed)->where('item_id', $item)->value('positive');
}

it('replays a wider window for one feed and leaves other feeds alone', function () {
    expect(positives('reviews'))->toBe(1)->and(positives('other'))->toBe(1);

    $this->artisan('recommender:rebuild-scores', ['feed' => 'reviews', '--days' => 90, '--force' => true])
        ->expectsOutputToContain('replayed 4 events')
        ->assertSuccessful();

    expect(positives('reviews'))->toBe(3)   // old likes now counted
        ->and(positives('other'))->toBe(1); // untouched
});

it('never double-counts when run twice or followed by update-scores', function () {
    $this->artisan('recommender:rebuild-scores', ['feed' => 'reviews', '--days' => 90, '--force' => true])->assertSuccessful();
    $this->artisan('recommender:rebuild-scores', ['feed' => 'reviews', '--days' => 90, '--force' => true])->assertSuccessful();
    $this->artisan('recommender:update-scores', ['feed' => 'reviews'])->assertSuccessful();

    expect(positives('reviews'))->toBe(3)
        ->and((int) DB::table('rec_item_scores')->where('feed', 'reviews')->where('item_id', '11')->value('exposure'))->toBe(1);
});

it('changes nothing on a dry run or when not confirmed', function () {
    $before = DB::table('rec_item_scores')->orderBy('id')->get()->toArray();

    $this->artisan('recommender:rebuild-scores', ['feed' => 'reviews', '--days' => 90, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
    $this->artisan('recommender:rebuild-scores', ['feed' => 'reviews', '--days' => 90])
        ->expectsConfirmation('Delete [reviews] scores and replay 90 days?', 'no')
        ->assertFailed();

    expect(DB::table('rec_item_scores')->orderBy('id')->get()->toArray())->toEqual($before);
});

it('blocks update-scores after an interrupted rebuild until the rebuild is rerun', function () {
    $feed = app(Recommender::class)->feed('reviews');
    ArrayInteractions::$failOnCall = ArrayInteractions::$betweenCalls + 1; // crash on the first replay slice

    expect(fn () => app(ScoreRebuilder::class)->run($feed, 90))->toThrow(RuntimeException::class);
    expect(app(StateStore::class)->get(ScoreRebuilder::markerKey('reviews')))->not->toBeNull();

    // The regular job refuses this feed (but still updates the other one).
    $this->artisan('recommender:update-scores')
        ->expectsOutputToContain('unfinished score rebuild')
        ->assertFailed();
    $this->artisan('recommender:doctor', ['feed' => 'reviews'])->expectsOutputToContain('Score rebuild was interrupted');

    ArrayInteractions::$failOnCall = null;
    $this->artisan('recommender:rebuild-scores', ['feed' => 'reviews', '--days' => 90, '--force' => true])
        ->expectsOutputToContain('interrupted — this run finishes it')
        ->assertSuccessful();

    expect(positives('reviews'))->toBe(3)
        ->and(app(StateStore::class)->get(ScoreRebuilder::markerKey('reviews')))->toBeNull();
    $this->artisan('recommender:update-scores', ['feed' => 'reviews'])->assertSuccessful();
});
