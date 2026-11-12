<?php

namespace SolutionForest\Recommender;

use Illuminate\Support\ServiceProvider;

class RecommenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/recommender.php', 'recommender');

        $this->app->singleton(Recommender::class);
        $this->app->singleton(Storage\StateStore::class);
        $this->app->singleton(Storage\ScoreStore::class);
        $this->app->singleton(Storage\NeighbourStore::class);
        $this->app->singleton(Feed\FeedCache::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register outside runningInConsole() so Artisan::call() from HTTP/Livewire works.
        $this->commands([
            Console\UpdateScoresCommand::class,
            Console\BuildNeighboursCommand::class,
            Console\ExplainCommand::class,
            Console\PruneCommand::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/recommender.php' => config_path('recommender.php'),
            ], 'recommender-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'recommender-migrations');
        }
    }
}
