<?php

declare(strict_types=1);

namespace Tests\Contract;

use Exception;
use DateTimeZone;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Deep-compare support for contract tests: golden JSON captured from Rails vs
 * a Laravel replay of the same request.
 *
 * Normalizations (and NOTHING else — this is the whole list, keep it that way):
 * - ISO8601 datetimes: "2025-01-01T00:00:00Z" vs "+00:00" offsets and
 *   fractional-second padding compare equal; both sides canonicalize to
 *   UTC "Y-m-d\TH:i:s.v\Z" (millisecond precision, matching Rails' default
 *   serialization).
 * - Float formatting on RATE fields only: 0.15 vs 0.15000000000000002 vs
 *   "0.15" compare equal (rounded to 12 decimals). Billing math values that
 *   are NOT rates (integer cents, string decimals) are compared exactly —
 *   do not widen this list without a contract-level reason.
 * - Volatile headers (X-Request-Id, Date) are dropped from header comparison;
 *   required headers are asserted separately by the harness.
 */
class Normalizer
{
    /**
     * Headers that differ on every capture and must never be compared.
     * Lowercased.
     */
    public const VOLATILE_HEADERS = ['x-request-id', 'date'];

    /**
     * JSON keys whose numeric values are compared as rates (float tolerance).
     */
    public const RATE_FIELDS = [
        'unit_amount',
        'amount_rate',
        'flat_amount',
        'rate',
        'matches_rate',
        'fixed_charge_rate',
    ];

    /**
     * Canonicalizes an ISO8601 datetime string, or returns null when the
     * value is not one.
     */
    public static function canonicalDatetime(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return null;
        }

        try {
            $datetime = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Canonicalizes a rate value (float tolerance + string-number coercion),
     * or returns null when the value is not numeric.
     */
    public static function canonicalRate(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 12);
        }

        if (is_string($value) && is_numeric($value)) {
            return round((float) $value, 12);
        }

        return null;
    }

    /**
     * Strips volatile headers (case-insensitive) from a header map.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (in_array(mb_strtolower((string) $name), self::VOLATILE_HEADERS, true)) {
                continue;
            }

            $normalized[(string) $name] = $value;
        }

        return $normalized;
    }

    /**
     * Recursively normalizes a decoded JSON document.
     */
    public static function normalizeValue(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $childKey => $childValue) {
                $normalized[$childKey] = self::normalizeValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $normalized;
        }

        if (is_string($value) && $key !== null) {
            $datetime = self::canonicalDatetime($value);

            if ($datetime !== null) {
                return $datetime;
            }
        }

        if ($key !== null && in_array(mb_strtolower($key), self::RATE_FIELDS, true)) {
            $rate = self::canonicalRate($value);

            if ($rate !== null) {
                return $rate;
            }
        }

        return $value;
    }

    /**
     * Deep-compares golden vs actual (already-decoded JSON) and returns the
     * list of differences. Empty array = match.
     *
     * @return list<array{path: string, expected: mixed, actual: mixed}>
     */
    public static function compare(mixed $golden, mixed $actual, string $path = '$'): array
    {
        $golden = self::normalizeValue($golden);
        $actual = self::normalizeValue($actual);

        if (gettype($golden) !== gettype($actual) && ! (self::bothScalar($golden, $actual))) {
            return [['path' => $path, 'expected' => $golden, 'actual' => $actual]];
        }

        if (is_array($golden) && is_array($actual)) {
            $isList = array_is_list($golden) && array_is_list($actual);

            if ($isList) {
                if (count($golden) !== count($actual)) {
                    return [['path' => $path, 'expected' => 'list of '.count($golden).' item(s)', 'actual' => 'list of '.count($actual).' item(s)']];
                }

                $diffs = [];

                foreach ($golden as $index => $expectedItem) {
                    $diffs = array_merge($diffs, self::compare($expectedItem, $actual[$index], $path.'['.$index.']'));
                }

                return $diffs;
            }

            $diffs = [];

            foreach ($golden as $key => $expectedValue) {
                if (! array_key_exists($key, $actual)) {
                    $diffs[] = ['path' => $path.'.'.$key, 'expected' => $expectedValue, 'actual' => null];

                    continue;
                }

                $diffs = array_merge($diffs, self::compare($expectedValue, $actual[$key], $path.'.'.$key));
            }

            foreach ($actual as $key => $actualValue) {
                if (! array_key_exists($key, $golden)) {
                    $diffs[] = ['path' => $path.'.'.$key, 'expected' => null, 'actual' => $actualValue];
                }
            }

            return $diffs;
        }

        if ($golden === $actual) {
            return [];
        }

        return [['path' => $path, 'expected' => $golden, 'actual' => $actual]];
    }

    /**
     * Convenience wrapper: parse raw JSON bodies and compare.
     *
     * @return list<array{path: string, expected: mixed, actual: mixed}>
     */
    public static function compareJson(string $goldenJson, string $actualJson): array
    {
        return self::compare(
            self::decode($goldenJson),
            self::decode($actualJson)
        );
    }

    /**
     * Renders diffs into a failure message (with an optional ledger row hint).
     *
     * @param  list<array{path: string, expected: mixed, actual: mixed}>  $diffs
     */
    public static function renderDiffs(array $diffs, ?string $ledgerRowId = null): string
    {
        $lines = $ledgerRowId !== null ? ["Ledger row: {$ledgerRowId}"] : [];

        foreach (array_slice($diffs, 0, 10) as $diff) {
            $lines[] = sprintf(
                '%s: expected %s, got %s',
                $diff['path'],
                json_encode($diff['expected'], JSON_UNESCAPED_SLASHES),
                json_encode($diff['actual'], JSON_UNESCAPED_SLASHES)
            );
        }

        if (count($diffs) > 10) {
            $lines[] = sprintf('… and %d more difference(s).', count($diffs) - 10);
        }

        return implode("\n", $lines);
    }

    private static function bothScalar(mixed $a, mixed $b): bool
    {
        return (is_scalar($a) || $a === null) && (is_scalar($b) || $b === null);
    }

    private static function decode(string $json): mixed
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON in contract comparison: '.json_last_error_msg());
        }

        return $decoded;
    }
}
