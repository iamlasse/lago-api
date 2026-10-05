<?php

declare(strict_types=1);

namespace App\Services\Utils;

/**
 * Port of Rails' Utils::ChargeProperties
 * (app/services/utils/charge_properties.rb).
 *
 * Charge properties are read in snake_case by the charge models and their
 * validators, while the payloads carrying them are written in the camelCase
 * of the GraphQL schema they were read from: a quote's billing_items is a
 * free-form JSON scalar, so nothing converts it on the way in. Underscoring
 * is idempotent, so a payload already written in snake_case normalizes to
 * itself and both spellings are accepted.
 */
final class ChargeProperties
{
    /**
     * custom_properties carries the keys the customer defined for their own
     * aggregation, so renaming them would change what that aggregation reads
     * at billing time.
     */
    public const PRESERVED_KEY = 'custom_properties';

    public static function underscoreKeys(mixed $properties): mixed
    {
        if (! is_array($properties) || array_is_list($properties)) {
            return $properties;
        }

        $normalized = [];

        foreach ($properties as $key => $value) {
            $normalizedKey = (string) preg_replace_callback(
                '/([a-z0-9])([A-Z])/',
                fn (array $m): string => $m[1].'_'.mb_strtolower($m[2]),
                (string) $key,
            );

            if ($normalizedKey === self::PRESERVED_KEY) {
                $normalized[$normalizedKey] = $value;

                continue;
            }

            $normalized[$normalizedKey] = self::underscoreValue($value);
        }

        return $normalized;
    }

    private static function underscoreValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_is_list($value)
                ? array_map(self::underscoreValue(...), $value)
                : self::underscoreKeys($value);
        }

        return $value;
    }
}
