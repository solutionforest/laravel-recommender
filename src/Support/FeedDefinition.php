<?php

namespace SolutionForest\Recommender\Support;

use InvalidArgumentException;

/**
 * A named feed with its configuration merged over the package defaults.
 */
final class FeedDefinition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly string $name,
        private readonly array $config,
    ) {
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $feed
     */
    public static function make(string $name, array $defaults, array $feed): self
    {
        if ($name === '' || preg_match('/[^A-Za-z0-9_\-.]/', $name)) {
            throw new InvalidArgumentException("Invalid recommender feed name [{$name}].");
        }

        return new self($name, array_replace($defaults, $feed));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return Value::int($this->config[$key] ?? $default, $default);
    }

    public function float(string $key, float $default = 0.0): float
    {
        return Value::float($this->config[$key] ?? $default, $default);
    }

    /**
     * @return array<string, float>  class => weight (keys are usually class-strings)
     */
    public function weighted(string $key): array
    {
        $out = [];
        foreach ((array) ($this->config[$key] ?? []) as $class => $weight) {
            if (is_int($class)) {
                // listed without a weight
                $out[Value::string($weight)] = 1.0;

                continue;
            }
            $out[$class] = Value::float($weight);
        }

        return $out;
    }

    /**
     * Non-empty string entries of a list setting (class names, attribute keys).
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        return array_values(array_filter((array) ($this->config[$key] ?? []), fn ($item) => is_string($item) && $item !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }
}
