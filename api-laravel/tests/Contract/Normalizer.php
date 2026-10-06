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
 * - Minted JWTs (`token` fields): the credential itself is per-run state —
 *   the two runtimes emit different base64url headers (the jwt gem emits
 *   {"alg":"HS256"}, firebase/php-jwt adds "typ":"JWT"). The CONTRACT is the
 *   claim set, so a 3-segment token value is canonicalized to its decoded
 *   claims and those must match exactly (sub, exp, login_method, …). Claims
 *   are equal because both sides mint at the same frozen instant.
 * - Minted row ids (`lago_id` fields holding a UUID): rows CREATED BY a
 *   captured request get a fresh SecureRandom.uuid on each side (Rails mints
 *   at capture, Laravel at replay — even two Rails runs would differ), so
 *   byte equality is unattainable by construction. A UUID under `lago_id`
 *   (and under `lago_invoice_id` / `lago_tax_id` / `lago_subscription_id` /
 *   `lago_coupon_id`, which reference rows
 *   minted by an earlier captured request) canonicalizes to "<uuid>";
 *   anything else under those keys (null, a slug, a foreign row's id format
 *   mismatch) still compares strictly, so a port that fails to mint or fails
 *   to echo an id is still a diff. Deterministic seeded ids are NOT
 *   protected by this rule when asserted in test bodies.
 * - Fee `item.lago_item_id` when the item type is "subscription": it carries
 *   the minted subscription's id (same rationale as lago_subscription_id).
 *   On charge items it is the seeded billable metric id and compares
 *   strictly — a port emitting the charge id still fails.
 * - URLs embedding minted row ids (`web_url`): every UUID segment in the
 *   string canonicalizes to "<uuid>" (invoice#web_url contains the customer
 *   and invoice uuids); the rest of the URL compares strictly.
 */
class Normalizer
{
    /**
     * Headers that differ on every capture and must never be compared.
     * Lowercased.
     */
    public const VOLATILE_HEADERS = ['x-request-id', 'date'];

    /**
     * JSON keys whose values are minted JWT credentials, compared by decoded
     * claims rather than bytes.
     */
    public const TOKEN_FIELDS = ['token'];

    /**
     * JSON keys whose UUID values are per-run minted row ids (rows created
     * by a captured request), compared as "a UUID" rather than by bytes.
     * lago_invoice_id / lago_tax_id appear on applied taxes and fees that
     * reference rows minted by an earlier captured request, so each runtime
     * echoes its own fresh ids; lago_subscription_id appears on fees and
     * billing_periods of invoices whose subscription was created by an
     * earlier captured request (same rationale); lago_coupon_id appears on
     * applied coupons whose coupon was created by an earlier captured
     * request (same rationale).
     */
    public const MINTED_ID_FIELDS = ['lago_id', 'lago_invoice_id', 'lago_tax_id', 'lago_subscription_id', 'lago_coupon_id'];

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
        'taxes_rate',
        'tax_rate',
    ];

    /**
     * URL fields embedding per-run minted row ids in their path (Rails'
     * invoice#web_url contains the customer and invoice uuids): every UUID
     * segment in the string canonicalizes to "<uuid>".
     */
    public const URL_FIELDS = ['web_url'];

    /**
     * JSON keys whose datetime values compare BYTE-STRICT: the
     * per-endpoint serialization format is itself the contract. The
     * analytics `month` renders "...Z" on gross_revenue /
     * overdue_balance / invoiced_usage / invoice_collection but
     * "...+00:00" on mrr — Rails' Postgres type path differs per
     * endpoint (mrr's generate-series upper bound is
     * `date_trunc('month', now())` → timestamptz; the other four bound
     * with CURRENT_DATE → timestamp without time zone, and the two
     * types serialize differently through ActiveRecord). The ISO8601
     * slack above must NOT smooth that over: a port emitting the wrong
     * per-endpoint format is a diff. See scripts/contract/README.md,
     * "Gotchas from the analytics capture wave".
     */
    public const STRICT_DATETIME_FIELDS = ['month'];

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
     * Canonicalizes a minted JWT to its decoded claim set (so two runtimes'
     * tokens compare equal when the contract — the claims — matches), or
     * returns null when the value is not a well-formed token.
     *
     * @return array<string, mixed>|null
     */
    public static function canonicalJwtClaims(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $payload = base64_decode(strtr(explode('.', $value)[1], '-_', '+/'), true);

        if ($payload === false) {
            return null;
        }

        $claims = json_decode($payload, true);

        return is_array($claims) ? $claims : null;
    }

    /**
     * Canonicalizes a per-run minted row id: any UUID under a MINTED_ID_FIELDS
     * key becomes "<uuid>" (each runtime mints its own), or returns null when
     * the value is not a UUID so everything else compares strictly.
     */
    public static function canonicalMintedId(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            return null;
        }

        return '<uuid>';
    }

    /**
     * Canonicalizes a URL that embeds per-run minted row ids: every UUID
     * segment becomes "<uuid>", the rest compares strictly.
     */
    public static function canonicalUrl(string $value): string
    {
        return (string) preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '<uuid>',
            $value,
        );
    }

    /**
     * Recursively normalizes a decoded JSON document.
     */
    public static function normalizeValue(mixed $value, ?string $key = null, bool $forceMinted = false): mixed
    {
        if (is_array($value)) {
            // On a fee `item`, type "subscription" means lago_item_id carries
            // the minted subscription's id (each runtime echoes its own); on
            // charge items it is the seeded billable metric id and compares
            // strictly. See test_minted_subscription_item_ids_compare_as_uuids.
            $subscriptionItem = ($value['type'] ?? null) === 'subscription';

            $normalized = [];

            foreach ($value as $childKey => $childValue) {
                $normalized[$childKey] = self::normalizeValue(
                    $childValue,
                    is_string($childKey) ? $childKey : null,
                    $subscriptionItem && $childKey === 'lago_item_id',
                );
            }

            return $normalized;
        }

        if (is_string($value) && $key !== null && ! in_array(mb_strtolower($key), self::STRICT_DATETIME_FIELDS, true)) {
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

        if ($key !== null && in_array(mb_strtolower($key), self::TOKEN_FIELDS, true)) {
            $claims = self::canonicalJwtClaims($value);

            if ($claims !== null) {
                return $claims;
            }
        }

        if ($key !== null && (in_array(mb_strtolower($key), self::MINTED_ID_FIELDS, true) || $forceMinted)) {
            $minted = self::canonicalMintedId($value);

            if ($minted !== null) {
                return $minted;
            }
        }

        if ($key !== null && in_array(mb_strtolower($key), self::URL_FIELDS, true) && is_string($value)) {
            return self::canonicalUrl($value);
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
