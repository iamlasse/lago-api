<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Plain unit tests (no app boot, no database) pinning the ONLY allowed
 * normalizations between Rails goldens and Laravel replays.
 */
class NormalizerTest extends TestCase
{
    public function test_identical_payloads_match(): void
    {
        $json = '{"customer":{"lago_id":"1a4a0d6e","name":"Org","amount_cents":1000}}';

        $this->assertSame([], Normalizer::compareJson($json, $json));
    }

    public function test_iso8601_z_and_offset_and_fraction_compare_equal(): void
    {
        $this->assertSame([], Normalizer::compareJson(
            '{"created_at":"2025-06-01T12:00:00Z"}',
            '{"created_at":"2025-06-01T12:00:00+00:00"}'
        ));

        $this->assertSame([], Normalizer::compareJson(
            '{"created_at":"2025-06-01T12:00:00.000Z"}',
            '{"created_at":"2025-06-01T12:00:00.000000+00:00"}'
        ));

        // Different instants must still be a diff.
        $diffs = Normalizer::compareJson(
            '{"created_at":"2025-06-01T12:00:00Z"}',
            '{"created_at":"2025-06-01T12:00:01Z"}'
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('$.created_at', $diffs[0]['path']);
    }

    public function test_non_datetime_strings_are_untouched(): void
    {
        $diffs = Normalizer::compareJson(
            '{"external_id":"2025-06-01T12:00:00Z"}',
            '{"external_id":"something-else"}'
        );

        $this->assertCount(1, $diffs);
    }

    public function test_rate_field_float_formatting_is_tolerated(): void
    {
        $this->assertSame([], Normalizer::compareJson(
            '{"unit_amount":0.15}',
            '{"unit_amount":0.15000000000000002}'
        ));

        $this->assertSame([], Normalizer::compareJson(
            '{"unit_amount":"0.15"}',
            '{"unit_amount":0.15}'
        ));

        // Non-rate numeric fields stay strict — integer cents are contract.
        $diffs = Normalizer::compareJson(
            '{"amount_cents":1000}',
            '{"amount_cents":1000.0}'
        );

        $this->assertCount(1, $diffs, 'integer cents must not gain float tolerance');
    }

    public function test_rate_tolerance_applies_to_nested_charge_properties(): void
    {
        $this->assertSame([], Normalizer::compareJson(
            '{"charges":[{"amount_rate":"1.500","amount_currency":"EUR"}]}',
            '{"charges":[{"amount_rate":1.5,"amount_currency":"EUR"}]}'
        ));
    }

    public function test_volatile_headers_are_stripped(): void
    {
        $golden = ['X-Request-Id' => 'req_rails_1', 'Date' => 'Sun, 01 Jun 2025 12:00:00 GMT', 'Content-Type' => 'application/json'];
        $actual = ['x-request-id' => 'req_laravel_9', 'date' => 'Mon, 02 Jun 2025 08:00:00 GMT', 'Content-Type' => 'application/json'];

        $this->assertSame(
            ['Content-Type' => 'application/json'],
            Normalizer::normalizeHeaders($actual)
        );

        $this->assertEquals(
            Normalizer::normalizeHeaders($golden),
            Normalizer::normalizeHeaders($actual)
        );
    }

    public function test_missing_and_extra_keys_are_reported(): void
    {
        $diffs = Normalizer::compare(
            ['customer' => ['lago_id' => 'a', 'name' => 'N']],
            ['customer' => ['lago_id' => 'a']]
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('$.customer.name', $diffs[0]['path']);
        $this->assertSame('N', $diffs[0]['expected']);
        $this->assertNull($diffs[0]['actual']);
    }

    public function test_list_length_and_order_are_reported(): void
    {
        $diffs = Normalizer::compare(['items' => [1, 2, 3]], ['items' => [1, 2]]);

        $this->assertCount(1, $diffs);
        $this->assertSame('$.items', $diffs[0]['path']);

        $diffs = Normalizer::compare(['items' => [1, 2]], ['items' => [2, 1]]);

        $this->assertCount(2, $diffs, 'ordered lists compare positionally');
        $this->assertSame('$.items[0]', $diffs[0]['path']);
    }

    public function test_render_diffs_includes_ledger_row_hint(): void
    {
        $diffs = Normalizer::compare(['a' => 1], ['a' => 2]);
        $message = Normalizer::renderDiffs($diffs, 'gql:mutation:createCustomer');

        $this->assertStringContainsString('gql:mutation:createCustomer', $message);
        $this->assertStringContainsString('$.a', $message);
    }

    /**
     * Minted tokens are per-run credentials (the two runtimes emit different
     * JWT headers), so they compare by decoded claims, not bytes.
     */
    public function test_minted_jwt_tokens_compare_by_claims(): void
    {
        // Same payload, different header bytes: jwt gem vs firebase/php-jwt.
        $railsStyle = 'eyJhbGciOiJIUzI1NiJ9.'.base64url_encode('{"sub":"u1","exp":100,"login_method":"email_password"}').'.sig';
        $phpStyle = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.'.base64url_encode('{"sub":"u1","exp":100,"login_method":"email_password"}').'.other_sig';

        $this->assertSame([], Normalizer::compareJson(
            '{"token":"'.$railsStyle.'"}',
            '{"token":"'.$phpStyle.'"}'
        ));

        // A different claim set is still a contract diff.
        $diffs = Normalizer::compareJson(
            '{"token":"'.$railsStyle.'"}',
            '{"token":"eyJhbGciOiJIUzI1NiJ9.'.base64url_encode('{"sub":"u2","exp":100,"login_method":"email_password"}').'.sig"}'
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('$.token.sub', $diffs[0]['path']);

        // Non-token values under `token` stay strict, non-JWT strings included.
        $diffs = Normalizer::compareJson(
            '{"token":"not-a-jwt"}',
            '{"token":"also-not-a-jwt"}'
        );

        $this->assertCount(1, $diffs);
    }
}

/** Hoist helper: test-local base64url encode (URL-safe, unpadded). */
function base64url_encode(string $value): string
{
    return mb_rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
