<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Cast for the frozen schema's native `varchar[]` columns
 * (organizations.email_settings / premium_integrations / feature_flags /
 * authentication_methods, api_keys… no — those are jsonb — webhook_endpoints
 * .event_types). Laravel's built-in `array` cast JSON-encodes, which
 * Postgres rejects for a varchar[] parameter; this cast speaks Postgres'
 * array literal format: {"a","b"}.
 *
 * @implements CastsAttributes<list<string>, iterable<string>>
 */
class PostgresArray implements CastsAttributes
{
    /**
     * @param  list<string>|null  $value
     * @return list<string>|null
     */
    public function get($model, string $key, $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $trimmed = mb_trim($value);

        if ($trimmed === '' || $trimmed === '{}') {
            return [];
        }

        // Unwrap one level of braces then split on commas outside quotes.
        $inner = mb_substr($trimmed, 1, -1);
        $items = str_getcsv($inner, ',', '"', '\\');

        return array_values(array_map(
            fn (string $item) => $item,
            array_filter($items, fn ($item) => $item !== null),
        ));
    }

    /**
     * @param  iterable<string>|null  $value
     */
    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $items = [];

        foreach ((array) $value as $item) {
            if ($item === null) {
                continue;
            }

            $items[] = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $item).'"';
        }

        return '{'.implode(',', $items).'}';
    }
}
