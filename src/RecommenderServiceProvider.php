<?php

namespace SolutionForest\Recommender;

use Illuminate\Support\Facades\Route;
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
        $this->registerDashboard();

        // Register outside runningInConsole() so Artisan::call() from HTTP/Livewire works.
        $this->commands([
            Console\UpdateScoresCommand::class,
            Console\BuildNeighboursCommand::class,
            Console\ExplainCommand::class,
            Console\PruneCommand::class,
            Console\StatusCommand::class,
            Console\MakeFeedCommand::class,
            Console\DoctorCommand::class,
            Console\RebuildScoresCommand::class,
            Console\BootstrapCommand::class,
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

    private function registerDashboard(): void
    {
        if (! config('recommender.dashboard.enabled', true) || $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'domain' => config('recommender.dashboard.domain'),
            'prefix' => trim((string) config('recommender.dashboard.path', 'recommender'), '/'),
            'middleware' => [...(array) config('recommender.dashboard.middleware', ['web']), Http\Authorize::class],
        ], function () {
            Route::get('/', Http\DashboardController::class)->name('recommender.dashboard');
        });
    }
}
