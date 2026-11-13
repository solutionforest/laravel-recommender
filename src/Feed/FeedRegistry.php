<?php

namespace SolutionForest\Recommender\Feed;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SolutionForest\Recommender\Support\FeedDefinition;

/**
 * Reads feeds from config: package defaults < app `defaults` < preset < feed.
 */
final class FeedRegistry
{
    /** @var array<string, FeedDefinition> */
    private array $definitions = [];

    public function get(string $name): FeedDefinition
    {
        if (isset($this->definitions[$name])) {
            return $this->definitions[$name];
        }

        $feeds = (array) config('recommender.feeds', []);
        if (! isset($feeds[$name])) {
            throw new InvalidArgumentException("Recommender feed [{$name}] is not configured.");
        }

        // Merged here (not only via mergeConfigFrom, which is shallow) so a
        // published config that lists only a few defaults still gets every key.
        $defaults = array_replace(self::packageConfig('defaults'), (array) config('recommender.defaults', []));
        $feed = (array) $feeds[$name];

        if (isset($feed['preset']) && $feed['preset'] !== '') {
            $presets = $this->presets();
            $preset = is_string($feed['preset']) ? $feed['preset'] : '';
            if (! isset($presets[$preset])) {
                throw new InvalidArgumentException("Recommender feed [{$name}] uses unknown preset [{$preset}]. Available: ".implode(', ', array_keys($presets)).'.');
            }
            $defaults = array_replace($defaults, (array) $presets[$preset]);
        }

        return $this->definitions[$name] = FeedDefinition::make($name, $defaults, $feed);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys((array) config('recommender.feeds', []));
    }

    /**
     * The names of the feeds whose `model` is $class.
     *
     * @return list<string>
     */
    public function forModel(string $class): array
    {
        $names = [];
        foreach ((array) config('recommender.feeds', []) as $name => $config) {
            if (is_array($config) && ($config['model'] ?? null) === $class) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The one feed whose `model` is $model.
     *
     * @param  class-string|Model  $model
     */
    public function nameFor(string|Model $model): string
    {
        $class = $model instanceof Model ? $model::class : $model;
        $names = $this->forModel($class);

        return match (count($names)) {
            1 => $names[0],
            0 => throw new InvalidArgumentException("No recommender feed has 'model' => {$class}::class. Add one to config/recommender.php or run php artisan recommender:bootstrap."),
            default => throw new InvalidArgumentException("Several recommender feeds use {$class} (".implode(', ', $names)."); pass the feed name, e.g. recommendedFor(\$user, '{$names[0]}')."),
        };
    }

    /**
     * Package presets, overridden / extended by the app's `presets`.
     *
     * @return array<string, mixed>
     */
    public function presets(): array
    {
        return array_replace(self::packageConfig('presets'), (array) config('recommender.presets', []));
    }

    /**
     * Is the package (and, when given, this feed) switched on?
     */
    public function enabled(?string $feed = null): bool
    {
        if (! (bool) config('recommender.enabled', true)) {
            return false;
        }

        return $feed === null || (bool) (config("recommender.feeds.{$feed}.enabled") ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function packageConfig(string $key): array
    {
        static $config = null;
        $config ??= (array) require __DIR__.'/../../config/recommender.php';

        return (array) ($config[$key] ?? []);
    }
}
