<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use Illuminate\Support\Str;

/**
 * Argument-shape helpers for the ported Rails resolvers/mutations.
 *
 * GraphQL-ruby hands Ruby resolvers snake_case keyword arguments (it
 * camelizes definitions for the wire and converts back on the way in). The
 * frozen SDL's argument/input names are camelCase, while the ported services
 * consume Rails-shaped snake_case params — so the wire args are converted
 * once, recursively, right at the resolver boundary.
 */
final class Args
{
    /**
     * Rails mutations receive the whole `input:` object as one keyword —
     * `def resolve(**args)` with `args[:input]` spread in. This unwraps it.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function input(array $args): array
    {
        $input = $args['input'] ?? [];

        return is_array($input) ? $input : [];
    }

    /**
     * Recursively lowerCamel → snake_case the keys of a wire args tree
     * (port of graphql-ruby's keyword conversion). List values are mapped
     * element-wise so nested input objects (billing_configuration,
     * shipping_address, …) convert too.
     *
     * @param  array<string|int, mixed>  $args
     * @return array<string|int, mixed>
     */
    public static function snakeKeys(array $args): array
    {
        $result = [];

        foreach ($args as $key => $value) {
            $snakeKey = is_string($key) ? Str::snake($key) : $key;

            if (is_array($value) && array_is_list($value)) {
                $result[$snakeKey] = array_map(
                    static fn ($item): mixed => is_array($item) ? self::snakeKeys($item) : $item,
                    $value,
                );
            } elseif (is_array($value)) {
                $result[$snakeKey] = self::snakeKeys($value);
            } else {
                $result[$snakeKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Rails' find_by(id:) casts an ill-formed uuid to no record; Postgres
     * would reject the literal, so wire ids are guarded at the boundary.
     */
    public static function uuidOrNull(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^\{?[0-9a-f]{8}\b-[0-9a-f]{4}\b-[0-9a-f]{4}\b-[0-9a-f]{4}\b-[0-9a-f]{12}$/i', $id) === 1
            ? $id
            : null;
    }
}
