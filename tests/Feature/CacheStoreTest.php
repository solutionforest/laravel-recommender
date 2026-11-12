<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Recommender as RecommenderService;
use SolutionForest\Recommender\Tests\Fixtures\StaticSource;

beforeEach(function () {
    StaticSource::$scores = [11 => 9.0, 21 => 8.0, 31 => 7.0, 41 => 6.0];
});

function useRecommenderCacheStore(string $store): void
{
    config(['recommender.cache_store' => $store]);
    app()->forgetInstance(FeedCache::class);
    app()->forgetInstance(RecommenderService::class);
    Cache::store($store)->flush();
}

function assertFeedWorksWithLocks(int|string $userId = 7001): void
{
    $cache = app(FeedCache::class);
    $feed = app(RecommenderService::class)->feed('reviews');

    expect($cache->supportsLocks())->toBeTrue();

    $lock = $cache->lock($feed, $userId);
    expect($lock->get())->toBeTrue();
    $lock->release();

    $page = Recommender::page('reviews', $userId, 1);
    expect($page->ids)->toBe([11, 21, 31])
        ->and($page->origin)->toBe('personal');
}

it('uses array cache with locks', function () {
    config(['cache.stores.array' => ['driver' => 'array', 'serialize' => false]]);
    useRecommenderCacheStore('array');

    assertFeedWorksWithLocks(7001);
});

it('uses file cache with locks', function () {
    $path = storage_path('framework/cache/data-recommender-test-'.uniqid('', true));
    mkdir($path, 0777, true);

    config([
        'cache.stores.file' => [
            'driver' => 'file',
            'path' => $path,
            'lock_path' => $path,
        ],
    ]);
    useRecommenderCacheStore('file');

    assertFeedWorksWithLocks(7002);

    // Clean up temp file cache directory.
    foreach (glob($path.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($path);
});

it('uses database cache with locks when cache tables exist', function () {
    Schema::dropIfExists('cache');
    Schema::dropIfExists('cache_locks');
    Schema::create('cache', function ($table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    Schema::create('cache_locks', function ($table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });

    config([
        'cache.stores.database' => [
            'driver' => 'database',
            'connection' => config('database.default'),
            'table' => 'cache',
            'lock_connection' => config('database.default'),
            'lock_table' => 'cache_locks',
        ],
    ]);
    useRecommenderCacheStore('database');

    assertFeedWorksWithLocks(7003);
});

it('uses redis cache with locks when redis is available', function () {
    if (! extension_loaded('redis') && ! class_exists(\Predis\Client::class)) {
        $this->markTestSkipped('Redis PHP extension / predis not installed.');
    }

    config([
        'database.redis.default' => [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'port' => getenv('REDIS_PORT') ?: '6379',
            'database' => 15,
        ],
        'cache.stores.redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ],
    ]);

    try {
        Cache::store('redis')->put('rec:ping', '1', 5);
        Cache::store('redis')->forget('rec:ping');
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis server not reachable: '.$e->getMessage());
    }

    useRecommenderCacheStore('redis');

    assertFeedWorksWithLocks(7004);
});
