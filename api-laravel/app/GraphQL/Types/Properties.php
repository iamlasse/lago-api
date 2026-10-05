<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `Properties` object (the charge
 * properties view — port of Rails' Types::Charges::Properties,
 * app/graphql/types/charges/properties.rb). Every other field resolves from
 * the snake_case hash keys through Lighthouse's default attribute lookup.
 */
class Properties
{
    /**
     * Rails properties.rb:26-30 — pricing_group_keys falls back to the
     * deprecated grouped_by key.
     *
     * @param  array<string, mixed>  $root
     * @return list<string>|null
     */
    public function pricingGroupKeys(array $root): ?array
    {
        $keys = $root['pricing_group_keys'] ?? null;

        if ($keys === null || $keys === '' || $keys === []) {
            $keys = $root['grouped_by'] ?? null;
        }

        return is_array($keys) ? $keys : null;
    }
}
