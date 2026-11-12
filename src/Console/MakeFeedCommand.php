<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeFeedCommand extends Command
{
    protected $signature = 'recommender:make-feed
        {name : Feed name, e.g. posts or reviews}
        {--model= : Eloquent model FQCN or short name (default: App\\Models\\{StudlySingular})}
        {--likes= : Likes table (default: {snake}_likes)}
        {--views= : Views table (default: {snake}_views)}
        {--item-column= : FK column on like/view tables (default: {snake}_id)}
        {--force : Overwrite existing class files}';

    protected $description = 'Scaffold InteractionSource, ItemFilter, CardTransformer and register a feed in config';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $nameArg = $this->argument('name');
        if (! is_string($nameArg) || $nameArg === '') {
            $this->error('Feed name is required (e.g. posts, reviews).');

            return self::FAILURE;
        }

        $feed = Str::of($nameArg)->snake()->lower()->toString();
        if ($feed === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $feed)) {
            $this->error('Feed name must be snake_case letters/numbers (e.g. posts, reviews).');

            return self::FAILURE;
        }

        $singular = Str::singular($feed);
        $studly = Str::studly($feed);
        $studlySingular = Str::studly($singular);
        $namespace = "App\\Recommendation\\{$studly}";
        $dir = app_path("Recommendation/{$studly}");

        $modelInput = $this->option('model');
        $modelInput = is_string($modelInput) && $modelInput !== '' ? $modelInput : null;
        $modelFqcn = $modelInput !== null
            ? (str_contains($modelInput, '\\') ? ltrim($modelInput, '\\') : 'App\\Models\\'.Str::studly($modelInput))
            : "App\\Models\\{$studlySingular}";
        $modelClass = class_basename($modelFqcn);

        $likesOpt = $this->option('likes');
        $viewsOpt = $this->option('views');
        $itemOpt = $this->option('item-column');
        $likes = is_string($likesOpt) && $likesOpt !== '' ? $likesOpt : "{$singular}_likes";
        $views = is_string($viewsOpt) && $viewsOpt !== '' ? $viewsOpt : "{$singular}_views";
        $itemColumn = is_string($itemOpt) && $itemOpt !== '' ? $itemOpt : "{$singular}_id";
        $publishedColumn = 'published';
        $ownerColumn = 'author_id';
        $titleColumn = 'title';

        $classes = [
            "{$studlySingular}InteractionSource" => 'interaction-source.stub',
            "Visible{$studlySingular}Filter" => 'item-filter.stub',
            "{$studlySingular}CardTransformer" => 'card-transformer.stub',
        ];

        $this->files->ensureDirectoryExists($dir);

        foreach ($classes as $class => $stub) {
            $path = "{$dir}/{$class}.php";
            if ($this->files->exists($path) && ! $this->option('force')) {
                $this->warn("Skipped (exists): {$path}");

                continue;
            }

            $contents = $this->replacePlaceholders($this->stub($stub), [
                'namespace' => $namespace,
                'class' => $class,
                'model' => $modelClass,
                'model_fqcn' => $modelFqcn,
                'likes_table' => $likes,
                'views_table' => $views,
                'item_column' => $itemColumn,
                'published_column' => $publishedColumn,
                'owner_column' => $ownerColumn,
                'title_column' => $titleColumn,
            ]);

            $this->files->put($path, $contents);
            $this->info("Created: {$path}");
        }

        $interaction = "{$namespace}\\{$studlySingular}InteractionSource";
        $filter = "{$namespace}\\Visible{$studlySingular}Filter";
        $cards = "{$namespace}\\{$studlySingular}CardTransformer";

        $this->ensureConfigPublished();
        $this->registerFeed($feed, $interaction, $filter, $cards);

        $this->newLine();
        $this->info("Feed [{$feed}] scaffolded.");
        $this->line('Next (see docs/tutorial.md):');
        $this->line("  1. Adjust table/column names in {$dir}");
        $this->line('  2. Add a route that calls Recommender::page()');
        $this->line("  3. php artisan recommender:update-scores {$feed}");
        $this->line("  4. php artisan recommender:build-neighbours {$feed}");
        $this->line("  5. php artisan recommender:explain {$feed} {userId}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function replacePlaceholders(string $stub, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $stub = str_replace('{{ '.$key.' }}', $value, $stub);
        }

        return $stub;
    }

    private function stub(string $name): string
    {
        $path = dirname(__DIR__, 2).'/stubs/'.$name;
        if (! $this->files->exists($path)) {
            throw new \RuntimeException("Missing stub [{$path}].");
        }

        return $this->files->get($path);
    }

    private function ensureConfigPublished(): void
    {
        $target = config_path('recommender.php');
        if ($this->files->exists($target)) {
            return;
        }

        $source = dirname(__DIR__, 2).'/config/recommender.php';
        $this->files->copy($source, $target);
        $this->info('Published: '.$target);
    }

    private function registerFeed(string $feed, string $interactions, string $filter, string $cards): void
    {
        $path = config_path('recommender.php');
        $contents = $this->files->get($path);

        if (preg_match("/['\"]".preg_quote($feed, '/')."['\"]\\s*=>/", $contents) === 1) {
            $this->warn("Config already has feed [{$feed}] — left unchanged. Wire classes manually if needed.");

            return;
        }

        $block = <<<PHP

        '{$feed}' => [
            'sources' => [
                \\SolutionForest\\Recommender\\Sources\\CoInteractionSource::class => 0.6,
                \\SolutionForest\\Recommender\\Sources\\TrendingSource::class => 0.2,
            ],
            'boosters' => [
                \\SolutionForest\\Recommender\\Boosters\\QualityBooster::class => 0.2,
            ],
            'events' => ['view' => 1.0, 'like' => 4.0],
            'positive_events' => ['like'],
            'exposure_events' => ['view'],
            'interactions' => \\{$interactions}::class,
            'filter' => \\{$filter}::class,
            'cards' => \\{$cards}::class,
        ],
PHP;

        $updated = null;
        if (preg_match("/'feeds'\\s*=>\\s*\\[\\s*\\]/", $contents) === 1) {
            $updated = preg_replace(
                "/'feeds'\\s*=>\\s*\\[\\s*\\]/",
                "'feeds' => [{$block}\n    ]",
                $contents,
                1,
            );
        } elseif (preg_match("/'feeds'\\s*=>\\s*\\[/", $contents) === 1) {
            $updated = preg_replace(
                "/('feeds'\\s*=>\\s*\\[)/",
                '$1'.$block,
                $contents,
                1,
            );
        }

        if (! is_string($updated)) {
            $this->error('Could not find feeds array in config/recommender.php — add the feed manually.');
            $this->line($block);

            return;
        }

        $this->files->put($path, $updated);
        $this->info("Registered feed [{$feed}] in config/recommender.php");
    }
}
