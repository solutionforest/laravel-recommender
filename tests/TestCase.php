<?php

namespace SolutionForest\Recommender\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SolutionForest\Recommender\RecommenderServiceProvider;
use SolutionForest\Recommender\Tests\Fixtures\ArrayCards;
use SolutionForest\Recommender\Tests\Fixtures\ArrayFilter;
use SolutionForest\Recommender\Tests\Fixtures\ArrayGroups;
use SolutionForest\Recommender\Tests\Fixtures\ArrayInteractions;
use SolutionForest\Recommender\Tests\Fixtures\StaticSource;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        ArrayInteractions::reset();
        ArrayFilter::reset();
        ArrayCards::reset();
        StaticSource::reset();
        if (getenv('RECOMMENDER_TEST_CACHE') === 'redis') {
            $this->app['cache']->store('redis')->flush(); // Redis outlives the test; start clean
        }
    }

    protected function getPackageProviders($app): array
    {
        return [RecommenderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        if (getenv('RECOMMENDER_TEST_DB') === 'mysql') {
            // Optional: run the suite against MySQL/MariaDB, e.g.
            // RECOMMENDER_TEST_DB=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rec_test vendor/bin/pest
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'unix_socket' => getenv('DB_SOCKET') ?: '',
                'database' => getenv('DB_DATABASE') ?: 'rec_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
        }
        $app['config']->set('cache.default', 'array');
        if (getenv('RECOMMENDER_TEST_CACHE') === 'redis') {
            // Optional: run the suite with a real Redis cache store, e.g.
            // RECOMMENDER_TEST_CACHE=redis REDIS_HOST=127.0.0.1 vendor/bin/pest (needs phpredis or predis/predis)
            $app['config']->set('database.redis.client', getenv('REDIS_CLIENT') ?: (extension_loaded('redis') ? 'phpredis' : 'predis'));
            $app['config']->set('database.redis.default', [
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => getenv('REDIS_PORT') ?: '6379',
                'database' => getenv('REDIS_DB') ?: '0',
            ]);
            $app['config']->set('database.redis.cache', $app['config']->get('database.redis.default'));
            $app['config']->set('cache.default', 'redis');
            $app['config']->set('recommender.cache_store', 'redis');
        }
        $app['config']->set('recommender.feeds', [
            'reviews' => [
                'sources' => [StaticSource::class => 1.0],
                'interactions' => ArrayInteractions::class,
                'filter' => ArrayFilter::class,
                'cards' => ArrayCards::class,
                'groups' => ArrayGroups::class,
                'exploration_ratio' => 0,
                'page_size' => 3,
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function configureFeed(array $overrides): void
    {
        $feeds = config('recommender.feeds');
        $feeds['reviews'] = array_replace($feeds['reviews'], $overrides);
        config(['recommender.feeds' => $feeds]);
        $this->app->forgetInstance(\SolutionForest\Recommender\Recommender::class);
    }
}
