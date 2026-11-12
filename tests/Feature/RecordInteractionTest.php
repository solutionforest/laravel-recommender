<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Recommender as RecommenderService;

beforeEach(function () {
    Schema::dropIfExists('rec_t_likes');
    Schema::dropIfExists('rec_t_views');
    Schema::dropIfExists('rec_t_strict');
    Schema::create('rec_t_likes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('post_id');
        $t->timestamp('created_at')->nullable();
        $t->unique(['user_id', 'post_id']);
    });
    Schema::create('rec_t_views', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('post_id');
        $t->timestamp('created_at')->nullable();
    });
    Schema::create('rec_t_strict', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('post_id');
        $t->string('source'); // extra NOT NULL column the package does not know
        $t->timestamp('created_at')->nullable();
    });

    config(['recommender.feeds' => [
        'posts' => [
            'item_column' => 'post_id',
            'interactions' => ['rec_t_likes' => 'like', 'rec_t_views' => 'view'],
        ],
        'dupes' => [
            'item_column' => 'post_id',
            'interactions' => ['rec_t_likes' => 'like', 'rec_t_views' => 'like'],
        ],
        'strict' => [
            'item_column' => 'post_id',
            'interactions' => ['rec_t_strict' => 'view'],
        ],
        'classes' => [
            'interactions' => SolutionForest\Recommender\Tests\Fixtures\ArrayInteractions::class,
        ],
    ]]);
    $this->app->forgetInstance(RecommenderService::class);
});

afterEach(function () {
    Schema::dropIfExists('rec_t_likes');
    Schema::dropIfExists('rec_t_views');
    Schema::dropIfExists('rec_t_strict');
});

it('keeps every repeated view', function () {
    expect(Recommender::recordInteraction('posts', 5, 1, 'view'))->toBeTrue()
        ->and(Recommender::recordInteraction('posts', 5, 1, 'view'))->toBeTrue()
        ->and(DB::table('rec_t_views')->count())->toBe(2);
});

it('sets the timestamp column itself and accepts a user model and an explicit time', function () {
    $when = Carbon\CarbonImmutable::parse('2026-01-02 03:04:05');
    Recommender::recordInteraction('posts', 5, new GenericUser(['id' => 9]), 'view', at: $when);
    Recommender::recordInteraction('posts', 6, 9, 'view');

    $rows = DB::table('rec_t_views')->orderBy('id')->get();
    expect((int) $rows[0]->user_id)->toBe(9)
        ->and((string) $rows[0]->created_at)->toBe('2026-01-02 03:04:05')
        ->and($rows[1]->created_at)->not->toBeNull();
});

it('reports "already recorded" for an idempotent like instead of throwing', function () {
    expect(Recommender::recordInteraction('posts', 5, 1, 'like', once: true))->toBeTrue()
        ->and(Recommender::recordInteraction('posts', 5, 1, 'like', once: true))->toBeFalse()
        ->and(Recommender::recordInteraction('posts', 6, 1, 'like', once: true))->toBeTrue()
        ->and(DB::table('rec_t_likes')->count())->toBe(2);
});

it('throws on a duplicate like when it is not idempotent, instead of hiding it', function () {
    Recommender::recordInteraction('posts', 5, 1, 'like');
    Recommender::recordInteraction('posts', 5, 1, 'like');
})->throws(InvalidArgumentException::class, 'once: true');

it('fails loudly when the table has an extra required column', function () {
    Recommender::recordInteraction('strict', 5, 1, 'view');
})->throws(Illuminate\Database\QueryException::class);

it('refuses an ambiguous event and accepts a table to choose', function () {
    expect(fn () => Recommender::recordInteraction('dupes', 5, 1, 'like'))
        ->toThrow(InvalidArgumentException::class, 'rec_t_likes, rec_t_views');

    expect(Recommender::recordInteraction('dupes', 5, 1, 'like', table: 'rec_t_views'))->toBeTrue()
        ->and(DB::table('rec_t_views')->count())->toBe(1);
});

it('rejects an unknown event and class-based feeds with a clear message', function () {
    expect(fn () => Recommender::recordInteraction('posts', 5, 1, 'share'))
        ->toThrow(InvalidArgumentException::class, 'Configured events: like, view');
    expect(fn () => Recommender::recordInteraction('classes', 5, 1, 'view'))
        ->toThrow(LogicException::class);
});

it('is available on the model through Recommendable', function () {
    $post = new class extends Illuminate\Database\Eloquent\Model
    {
        use SolutionForest\Recommender\Eloquent\Recommendable;

        protected $table = 'posts';

        public $incrementing = false;
    };
    config(['recommender.feeds.posts.model' => $post::class]);
    $this->app->forgetInstance(RecommenderService::class);
    $post->setAttribute('id', 12);

    expect($post->recordInteraction(3, 'like', once: true))->toBeTrue()
        ->and($post->recordInteraction(3, 'like', once: true))->toBeFalse()
        ->and((int) DB::table('rec_t_likes')->value('post_id'))->toBe(12);
});
