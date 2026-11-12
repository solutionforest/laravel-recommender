<?php

namespace SolutionForest\Recommender\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeFeedCommand extends Command
{
    protected $signature = 'recommender:make-feed
        {name? : Feed name, e.g. posts or reviews (optional — asked interactively)}
        {--preset= : blog|products|events|reviews|custom (skips preset prompt)}
        {--model= : Eloquent model FQCN or short name}
        {--likes= : Likes table}
        {--views= : Views table}
        {--purchases= : Optional third interaction table (e.g. product_purchases)}
        {--purchase-event= : Event name for --purchases table (default: purchase)}
        {--item-column= : FK column on interaction tables}
        {--owner-column= : Owner column on the item model (default author_id / user_id / seller_id by preset)}
        {--published-column= : Published / visibility column on the item (default: published)}
        {--title-column= : Title column for cards (default: title)}
        {--force : Overwrite existing class files}';

    protected $description = 'Interactively scaffold InteractionSource, ItemFilter, CardTransformer and register a feed (use -n for non-interactive)';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Respect global Symfony `-n` / `--no-interaction` (do not redeclare that option).
        $interactive = $this->input->isInteractive();

        $preset = $this->resolvePreset($interactive);
        $defaults = $this->presetDefaults($preset);

        $feed = $this->resolveFeedName($interactive, $defaults['feed'] ?? null);
        if ($feed === null) {
            return self::FAILURE;
        }

        $singular = Str::singular($feed);
        $studly = Str::studly($feed);
        $studlySingular = Str::studly($singular);
        $namespace = "App\\Recommendation\\{$studly}";
        $dir = app_path("Recommendation/{$studly}");

        $modelFqcn = $this->resolveModel($interactive, $defaults['model'] ?? "App\\Models\\{$studlySingular}", $studlySingular);
        $modelClass = class_basename($modelFqcn);

        $likes = $this->resolveString($interactive, 'likes', 'Likes table', $defaults['likes'] ?? "{$singular}_likes");
        $views = $this->resolveString($interactive, 'views', 'Views table', $defaults['views'] ?? "{$singular}_views");
        $itemColumn = $this->resolveString($interactive, 'item-column', 'Item FK column on interaction tables', $defaults['item'] ?? "{$singular}_id");
        $ownerColumn = $this->resolveString($interactive, 'owner-column', 'Owner column on item (hide own)', $defaults['owner'] ?? 'author_id');
        $publishedColumn = $this->resolveString($interactive, 'published-column', 'Published column on item', $defaults['published'] ?? 'published');
        $titleColumn = $this->resolveString($interactive, 'title-column', 'Title column on item', $defaults['title'] ?? 'title');

        $extra = $this->resolveExtraEvent($interactive, $defaults, $singular);

        /** @var array<string, string> $tables table => event */
        $tables = [
            $likes => 'like',
            $views => 'view',
        ];
        if ($extra !== null) {
            $tables[$extra['table']] = $extra['event'];
        }

        $eventsConfig = ['view' => 1.0, 'like' => 4.0];
        $positive = ['like'];
        if ($extra !== null) {
            $eventsConfig[$extra['event']] = $extra['weight'];
            $positive[] = $extra['event'];
        }

        if ($interactive) {
            $this->newLine();
            $this->table(
                ['Setting', 'Value'],
                [
                    ['preset', $preset],
                    ['feed', $feed],
                    ['model', $modelFqcn],
                    ['events', implode(', ', array_map(fn ($e, $w) => "{$e}={$w}", array_keys($eventsConfig), $eventsConfig))],
                    ['tables', implode(', ', array_map(fn ($t, $e) => "{$t}→{$e}", array_keys($tables), $tables))],
                    ['item column', $itemColumn],
                    ['owner / published / title', "{$ownerColumn} / {$publishedColumn} / {$titleColumn}"],
                    ['classes dir', $dir],
                ],
            );

            if (! $this->confirm('Create these files and register the feed?', true)) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        $classes = [
            "{$studlySingular}InteractionSource" => 'interaction-source.stub',
            "Visible{$studlySingular}Filter" => 'item-filter.stub',
            "{$studlySingular}CardTransformer" => 'card-transformer.stub',
        ];

        $this->files->ensureDirectoryExists($dir);

        $tablesEntries = collect($tables)
            ->map(fn (string $event, string $table) => "        '{$table}' => '{$event}',")
            ->implode("\n");

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
                'tables_entries' => $tablesEntries,
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
        $this->registerFeed($feed, $interaction, $filter, $cards, $eventsConfig, $positive);

        $this->newLine();
        $this->info("Feed [{$feed}] scaffolded.");
        $this->line('Next (see docs/tutorial.md):');
        $this->line("  1. Adjust table/column names in {$dir} if needed");
        $this->line('  2. Add a route / Livewire that calls Recommender::page()');
        $this->line("  3. php artisan recommender:update-scores {$feed}");
        $this->line("  4. php artisan recommender:build-neighbours {$feed}");
        $this->line("  5. php artisan recommender:explain {$feed} {userId}");
        if ($extra !== null) {
            $this->comment("  Tip: neighbours use all TABLES — trim views out of userHistories() if you only want strong signals.");
        }

        return self::SUCCESS;
    }

    private function resolvePreset(bool $interactive): string
    {
        $presetOpt = $this->option('preset');
        if (is_string($presetOpt) && $presetOpt !== '') {
            $preset = strtolower($presetOpt);
            if (! in_array($preset, ['blog', 'products', 'events', 'reviews', 'custom'], true)) {
                $this->warn("Unknown preset [{$preset}] — using custom.");

                return 'custom';
            }

            return $preset;
        }

        if (! $interactive) {
            return 'custom';
        }

        $label = $this->choice(
            'Choose a starting preset',
            [
                'blog — posts with like + view',
                'products — catalog with like + view + purchase',
                'events — meetup listing with like + view + join',
                'reviews — UGC with like + view',
                'custom — answer every prompt',
            ],
            0,
        );

        return Str::before($label, ' —');
    }

    /**
     * @return array{feed?: string, model?: string, likes?: string, views?: string, item?: string, owner?: string, published?: string, title?: string, extra?: array{table: string, event: string, weight: float}|null}
     */
    private function presetDefaults(string $preset): array
    {
        return match ($preset) {
            'blog' => [
                'feed' => 'posts',
                'model' => 'App\\Models\\Post',
                'likes' => 'post_likes',
                'views' => 'post_views',
                'item' => 'post_id',
                'owner' => 'author_id',
                'extra' => null,
            ],
            'products' => [
                'feed' => 'products',
                'model' => 'App\\Models\\Product',
                'likes' => 'product_likes',
                'views' => 'product_views',
                'item' => 'product_id',
                'owner' => 'seller_id',
                'extra' => ['table' => 'product_purchases', 'event' => 'purchase', 'weight' => 10.0],
            ],
            'events' => [
                'feed' => 'events',
                'model' => 'App\\Models\\Event',
                'likes' => 'event_likes',
                'views' => 'event_views',
                'item' => 'event_id',
                'owner' => 'host_id',
                'extra' => ['table' => 'event_joins', 'event' => 'join', 'weight' => 10.0],
            ],
            'reviews' => [
                'feed' => 'reviews',
                'model' => 'App\\Models\\Comment',
                'likes' => 'user_fav_comments',
                'views' => 'user_clicks',
                'item' => 'comment_id',
                'owner' => 'user_id',
                'extra' => null,
            ],
            default => [
                'extra' => null,
            ],
        };
    }

    private function resolveFeedName(bool $interactive, ?string $default): ?string
    {
        $nameArg = $this->argument('name');
        $raw = is_string($nameArg) && $nameArg !== '' ? $nameArg : null;

        if ($raw === null && $interactive) {
            $raw = $this->ask('Feed name (snake_case)', $default ?? 'posts');
        }

        if (! is_string($raw) || $raw === '') {
            $this->error('Feed name is required (e.g. posts, reviews).');

            return null;
        }

        $feed = Str::of($raw)->snake()->lower()->toString();
        if ($feed === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $feed)) {
            $this->error('Feed name must be snake_case letters/numbers (e.g. posts, reviews).');

            return null;
        }

        return $feed;
    }

    private function resolveModel(bool $interactive, string $default, string $studlySingular): string
    {
        $modelInput = $this->option('model');
        $modelInput = is_string($modelInput) && $modelInput !== '' ? $modelInput : null;

        if ($modelInput === null && $interactive) {
            $modelInput = $this->ask('Eloquent model (FQCN or short name)', $default);
        }

        $modelInput = is_string($modelInput) && $modelInput !== '' ? $modelInput : $default;

        return str_contains($modelInput, '\\')
            ? ltrim($modelInput, '\\')
            : 'App\\Models\\'.Str::studly($modelInput);
    }

    private function resolveString(bool $interactive, string $option, string $question, string $default): string
    {
        $value = $this->option($option);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($interactive) {
            return (string) $this->ask($question, $default);
        }

        return $default;
    }

    /**
     * @param  array{extra?: array{table: string, event: string, weight: float}|null}  $defaults
     * @return array{table: string, event: string, weight: float}|null
     */
    private function resolveExtraEvent(bool $interactive, array $defaults, string $singular): ?array
    {
        $purchasesOpt = $this->option('purchases');
        if (is_string($purchasesOpt) && $purchasesOpt !== '') {
            $eventOpt = $this->option('purchase-event');
            $event = is_string($eventOpt) && $eventOpt !== '' ? $eventOpt : 'purchase';

            return [
                'table' => $purchasesOpt,
                'event' => $event,
                'weight' => 10.0,
            ];
        }

        if (isset($defaults['extra']) && is_array($defaults['extra'])) {
            if (! $interactive) {
                return $defaults['extra'];
            }

            if ($this->confirm(
                "Include third event [{$defaults['extra']['event']}] on table [{$defaults['extra']['table']}]?",
                true,
            )) {
                return $defaults['extra'];
            }

            return null;
        }

        if (! $interactive) {
            return null;
        }

        if (! $this->confirm('Add a third interaction type (purchase / share / bookmark / …)?', false)) {
            return null;
        }

        $table = (string) $this->ask('Third event table', "{$singular}_purchases");
        $event = (string) $this->ask('Event name (config key)', 'purchase');
        $weight = (float) $this->ask('Event weight for trending (like=4, purchase≈10)', '10');

        return [
            'table' => $table,
            'event' => $event !== '' ? $event : 'purchase',
            'weight' => $weight > 0 ? $weight : 10.0,
        ];
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

    /**
     * @param  array<string, float>  $events
     * @param  list<string>  $positive
     */
    private function registerFeed(
        string $feed,
        string $interactions,
        string $filter,
        string $cards,
        array $events,
        array $positive,
    ): void {
        $path = config_path('recommender.php');
        $contents = $this->files->get($path);

        if (preg_match("/['\"]".preg_quote($feed, '/')."['\"]\\s*=>/", $contents) === 1) {
            $this->warn("Config already has feed [{$feed}] — left unchanged. Wire classes manually if needed.");

            return;
        }

        $eventsExport = var_export($events, true);
        $positiveExport = var_export($positive, true);

        $block = <<<PHP

        '{$feed}' => [
            'sources' => [
                \\SolutionForest\\Recommender\\Sources\\CoInteractionSource::class => 0.6,
                \\SolutionForest\\Recommender\\Sources\\TrendingSource::class => 0.2,
            ],
            'boosters' => [
                \\SolutionForest\\Recommender\\Boosters\\QualityBooster::class => 0.2,
            ],
            'events' => {$eventsExport},
            'positive_events' => {$positiveExport},
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
