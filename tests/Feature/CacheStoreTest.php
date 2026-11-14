<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

function freshCache(): \SolutionForest\Recommender\Feed\FeedCache
{
    app()->forgetInstance(\SolutionForest\Recommender\Feed\FeedCache::class);
    app('cache')->forgetDriver(['redis']);

    return app(\SolutionForest\Recommender\Feed\FeedCache::class);
}

it('picks Redis automatically when the app has a working Redis store', function () {
    skipOnRedis('fakes the redis store with the array driver');
    config(['recommender.cache_store' => null, 'cache.stores.redis' => ['driver' => 'array']]); // stands in for a reachable Redis

    expect(freshCache()->storeName())->toBe('redis')
        ->and(Cache::get('recommender:store-probe'))->toBe('redis'); // remembered for an hour
});

it('falls back to the default store when Redis is configured but unreachable', function () {
    skipOnRedis('replaces the redis connection config');
    config([
        'recommender.cache_store' => null,
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'nowhere'],
        'database.redis.nowhere' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2],
    ]);

    expect(freshCache()->storeName())->toBeNull()
        ->and(Cache::get('recommender:store-probe'))->toBe('default')
        ->and(Recommender::page('reviews', 7)->items)->toBeArray(); // still serves
});

it('uses the store you set and never probes', function () {
    config(['recommender.cache_store' => 'array', 'cache.stores.redis' => ['driver' => 'array']]);

    expect(freshCache()->storeName())->toBe('array')
        ->and(Cache::get('recommender:store-probe'))->toBeNull();
});

function databaseCacheTables(): void
{
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
    config(['cache.stores.database' => [
        'driver' => 'database', 'connection' => config('database.default'), 'table' => 'cache',
        'lock_connection' => config('database.default'), 'lock_table' => 'cache_locks',
    ]]);
}

it('prune removes only this package\'s expired rows from a database cache', function () {
    databaseCacheTables();
    useRecommenderCacheStore('database');
    $prefix = Cache::store('database')->getPrefix();
    DB::table('cache')->insert([
        ['key' => $prefix.'rec:reviews:seen:1', 'value' => 'x', 'expiration' => time() - 10],   // ours, expired
        ['key' => $prefix.'rec:reviews:seen:2', 'value' => 'x', 'expiration' => time() + 3600], // ours, live
        ['key' => $prefix.'app:session:9', 'value' => 'x', 'expiration' => time() - 10],        // the app's
    ]);

    $this->artisan('recommender:prune')->expectsOutputToContain('Removed 1 expired rows')->assertSuccessful();

    expect(DB::table('cache')->orderBy('key')->pluck('key')->all())
        ->toBe([$prefix.'app:session:9', $prefix.'rec:reviews:seen:2']);
});

it('still serves the page when saving the seen list fails', function () {
    Cache::extend('flaky', fn () => Cache::repository(new class extends \Illuminate\Cache\ArrayStore {
        public function put($key, $value, $seconds)
        {
            if (str_contains($key, ':seen:')) {
                throw new RuntimeException('Deadlock found when trying to get lock');
            }

            return parent::put($key, $value, $seconds);
        }
    }));
    config(['cache.stores.flaky' => ['driver' => 'flaky']]);
    useRecommenderCacheStore('flaky');
    \Illuminate\Support\Facades\Log::spy();

    $page = Recommender::page('reviews', 7);

    expect($page->items)->not->toBeEmpty();
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'seen-set write failed'));
});

it('names the cache store in doctor', function () {
    databaseCacheTables();
    useRecommenderCacheStore('database');

    $this->artisan('recommender:doctor', ['feed' => 'reviews'])
        ->expectsOutputToContain('Cache: database — fine for small and medium apps');
});
