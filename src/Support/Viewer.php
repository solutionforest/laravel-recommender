<?php

namespace SolutionForest\Recommender\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @internal Turns "a user model, an id or null" into an id + attributes.
 */
final class Viewer
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: int|string|null, 1: array<string, mixed>}  user id, attributes (with 'user' when a model was given)
     */
    public static function resolve(Authenticatable|int|string|null $user, array $attributes = []): array
    {
        if ($user instanceof Authenticatable) {
            $attributes['user'] ??= $user;
            $id = $user->getAuthIdentifier();

            return [is_int($id) || is_string($id) ? $id : null, $attributes];
        }

        return [$user, $attributes];
    }

    public static function id(Authenticatable|int|string $user, string $action): int|string
    {
        return self::resolve($user)[0] ?? throw new \InvalidArgumentException("Cannot {$action} for a user without an id.");
    }
}
