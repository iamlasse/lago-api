<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Cast for the frozen schema's jsonb hash columns (charges.properties,
 * charge_filters.properties, fixed_charges.properties…).
 *
 * The frozen schema defaults charges.properties to the jsonb STRING `"{}"`
 * (not an object — see structure.sql `DEFAULT '"{}"'::jsonb`), and Rails
 * tolerates reading it back as a String. This cast decodes the jsonb value
 * verbatim: objects/arrays come back as PHP arrays, the legacy string default
 * comes back as the string it is (Rails parity — callers must tolerate both).
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class JsonbProperties implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $decoded;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            // Pre-encoded JSON (or a scalar jsonb string like the `"{}"`
            // default) — pass through verbatim, matching Rails' assignment of
            // any JSON-serializable value.
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }

        return json_encode($value);
    }
}
