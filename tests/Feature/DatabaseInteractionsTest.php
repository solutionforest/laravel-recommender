<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SolutionForest\Recommender\Interactions\DatabaseInteractions;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Support\Bindings;
use SolutionForest\Recommender\Support\FeedDefinition;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../Fixtures/migrations');

    $this->feed = FeedDefinition::make('videos', [], [
        'interactions' => [
            'video_plays' => 'view',
            'video_favourites' => ['event' => 'like', 'item' => 'clip_uuid'],
        ],
        'item_column' => 'video_uuid',
        'user_column' => 'viewer_uuid',
        'timestamp_column' => 'played_at',
        'positive_events' => ['like'],
    ]);
    $this->source = new DatabaseInteractions($this->feed);

    $this->alice = '0193a1e0-0000-7000-8000-000000000001';
    $this->bob = '0193a1e0-0000-7000-8000-000000000002';
    $this->v1 = '0193a1e0-0000-7000-8000-0000000000a1';
    $this->v2 = '0193a1e0-0000-7000-8000-0000000000a2';
    $this->v3 = '0193a1e0-0000-7000-8000-0000000000a3';

    DB::table('video_plays')->insert([
        ['viewer_uuid' => $this->alice, 'video_uuid' => $this->v3, 'played_at' => '2026-11-10 09:00:00'],
        ['viewer_uuid' => $this->bob, 'video_uuid' => $this->v1, 'played_at' => '2026-11-10 10:00:00'],
    ]);
    DB::table('video_favourites')->insert([
        ['viewer_uuid' => $this->alice, 'clip_uuid' => $this->v1, 'played_at' => '2026-11-10 08:00:00'],
        ['viewer_uuid' => $this->alice, 'clip_uuid' => $this->v2, 'played_at' => '2026-11-10 11:00:00'],
        ['viewer_uuid' => $this->alice, 'clip_uuid' => $this->v1, 'played_at' => '2026-11-10 07:00:00'],
        ['viewer_uuid' => $this->bob, 'clip_uuid' => $this->v2, 'played_at' => '2026-11-10 12:00:00'],
    ]);
});

it('streams every event in the window with string ids and per-table columns', function () {
    $events = collect($this->source->between(
        CarbonImmutable::parse('2026-11-10 07:30:00'),
        CarbonImmutable::parse('2026-11-10 11:00:00'),
    ))->map(fn ($e) => [$e->userId, $e->itemId, $e->event])->all();

    expect($events)->toEqualCanonicalizing([
        [$this->alice, $this->v3, 'view'],
        [$this->bob, $this->v1, 'view'],
        [$this->alice, $this->v1, 'like'],
        [$this->alice, $this->v2, 'like'],
    ]);
});

it('builds histories and recent items from positive events only, newest first', function () {
    $histories = iterator_to_array($this->source->userHistories(CarbonImmutable::parse('2026-11-01'), 10));

    expect($histories)->toBe([
        $this->alice => [$this->v2, $this->v1],
        $this->bob => [$this->v2],
    ])->and($this->source->recentItems($this->alice, 1))->toBe([$this->v2]);
});

it('falls back to every table when no history event matches', function () {
    $source = new DatabaseInteractions(FeedDefinition::make('videos', [], ['history_events' => ['share']] + $this->feed->toArray()));

    expect($source->recentItems($this->alice, 5))->toBe([$this->v2, $this->v3, $this->v1]);
});

it('derives the item column from the model name', function () {
    $feed = FeedDefinition::make('articles', [], [
        'model' => \SolutionForest\Recommender\Examples\Simple\Models\Article::class,
        'interactions' => ['fixture_article_likes' => 'like'],
    ]);
    DB::table('fixture_article_likes')->insert(['user_id' => 9, 'article_id' => 4, 'created_at' => now()]);

    expect((new DatabaseInteractions($feed))->recentItems(9, 5))->toBe([4]);
});

it('explains what is missing when a feed has neither model nor cards', function () {
    config(['recommender.feeds' => ['bare' => ['interactions' => ['x' => 'like']]]]);
    $feed = app(Recommender::class)->feed('bare');

    expect(fn () => app(Bindings::class)->cards($feed))->toThrow(InvalidArgumentException::class, "needs a 'model' or a 'cards' transformer")
        ->and(fn () => app(Bindings::class)->interactions($feed))->toThrow(InvalidArgumentException::class, "needs an 'item_column'");
});

it('pages through more users than one chunk without skipping any', function () {
    $rows = [];
    foreach (range(1, 1001) as $u) {
        $rows[] = ['viewer_uuid' => sprintf('0193a1e0-0000-7000-8000-%012d', $u), 'clip_uuid' => $this->v1, 'played_at' => '2026-11-10 08:00:00'];
    }
    DB::table('video_favourites')->delete();
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('video_favourites')->insert($chunk);
    }

    $users = array_keys(iterator_to_array($this->source->userHistories(CarbonImmutable::parse('2026-11-01'), 10)));

    expect($users)->toHaveCount(1001)->and(array_unique($users))->toHaveCount(1001);
});
