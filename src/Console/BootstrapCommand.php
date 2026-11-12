<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use SolutionForest\Recommender\Recommender;

/**
 * One command from `composer require` to a working feed:
 * migrate → make-feed → first scores + similar items → doctor → next steps.
 */
class BootstrapCommand extends Command
{
    protected $signature = 'recommender:bootstrap
        {name? : Feed name, e.g. posts}
        {--preset= : blog|products|events|reviews|custom}
        {--model= : Eloquent model FQCN}
        {--likes= : Likes table}
        {--views= : Views table}
        {--purchases= : Optional third interaction table}
        {--purchase-event= : Event name for --purchases}
        {--item-column= : Item id column on interaction tables}
        {--owner-column= : Owner column on the item}
        {--published-column= : Published column on the item}
        {--title-column= : Title column for cards}
        {--classes : Scaffold classes instead of a config-only feed}
        {--skip-migrate : Do not run migrations}
        {--force : Run migrations in production without asking}';

    protected $description = 'Set up a feed in one go: migrate, register it, build the first scores and similar items, check everything';

    public function handle(): int
    {
        $this->components->info('Laravel Recommender — bootstrap');

        if (! $this->option('skip-migrate')) {
            $this->components->task('Migrate', fn () => $this->callSilently('migrate', array_filter(['--force' => $this->option('force')])) === self::SUCCESS);
        }

        $before = array_keys((array) config('recommender.feeds', []));
        $options = ['name' => $this->argument('name')];
        foreach (['preset', 'model', 'likes', 'views', 'purchases', 'purchase-event', 'item-column', 'owner-column', 'published-column', 'title-column'] as $option) {
            $options["--{$option}"] = $this->option($option);
        }
        $options['--classes'] = $this->option('classes');
        $options['--no-interaction'] = ! $this->input->isInteractive();

        if ($this->call('recommender:make-feed', array_filter($options, fn ($v) => $v !== null && $v !== false)) !== self::SUCCESS) {
            return self::FAILURE;
        }

        // make-feed wrote config/recommender.php: load it for the next steps.
        $this->reloadConfig();
        $after = array_keys((array) config('recommender.feeds', []));
        $name = is_string($this->argument('name')) && $this->argument('name') !== ''
            ? $this->argument('name')
            : (array_values(array_diff($after, $before))[0] ?? null);

        if ($name === null || ! in_array($name, $after, true)) {
            $this->components->error('No feed was registered; nothing else to do.');

            return self::FAILURE;
        }

        $ok = true;
        foreach (['recommender:update-scores' => "Scores for [{$name}]", 'recommender:build-neighbours' => "Similar items for [{$name}]"] as $command => $label) {
            $this->components->task($label, function () use ($command, $name, &$ok) {
                try {
                    $passed = $this->callSilently($command, ['feed' => $name]) === self::SUCCESS;
                } catch (\Throwable) {
                    $passed = false; // doctor below explains what is wrong
                }
                $ok = $ok && $passed;

                return $passed;
            });
        }

        $this->newLine();
        $healthy = $this->call('recommender:doctor', ['feed' => $name]) === self::SUCCESS;

        $this->components->info($healthy ? "Feed [{$name}] is ready." : "Feed [{$name}] is registered — fix the checks above, then run `php artisan recommender:doctor {$name}`.");
        $model = config("recommender.feeds.{$name}.model");
        $serve = is_string($model) && $model !== ''
            ? class_basename($model).'::recommendedFor($r->user()));   // model uses Recommendable'
            : "recommendations('{$name}', \$r->user()));";
        $this->line('  Route:     '."Route::get('/{$name}/recommended', fn (Request \$r) => {$serve}");
        $this->line('  Dashboard: /recommender  (production: Gate::define(\'viewRecommender\', …))');
        $this->line('  Schedule:  automatic — just keep Laravel\'s scheduler running (schedule:work / cron)');

        return $healthy && $ok ? self::SUCCESS : self::FAILURE;
    }

    private function reloadConfig(): void
    {
        $path = config_path('recommender.php');
        if (is_file($path)) {
            $fresh = require $path;
            if (is_array($fresh)) {
                config(['recommender' => array_replace((array) config('recommender', []), $fresh)]);
            }
        }
        $this->laravel->forgetInstance(Recommender::class);
    }
}
