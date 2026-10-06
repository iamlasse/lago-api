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

    /**
     * Minted row ids are per-run state: rows created BY a captured request
     * get a fresh UUID on each side (Rails at capture, Laravel at replay),
     * so a UUID under `lago_id` compares as "a UUID". Everything else under
     * that key — null, a slug, a non-UUID string — stays strict.
     */
    public function test_minted_lago_ids_compare_as_uuids(): void
    {
        $this->assertSame([], Normalizer::compareJson(
            '{"customer":{"lago_id":"85d10c0d-364e-423a-959c-f6f754435a31"}}',
            '{"customer":{"lago_id":"8e82452d-c459-4573-9d8d-c36a3b47ec19"}}'
        ));

        // Nested minted ids (metadata rows) follow the same rule.
        $this->assertSame([], Normalizer::compareJson(
            '{"metadata":[{"lago_id":"82423005-b784-4956-8081-bdf6252e332b","key":"k"}]}',
            '{"metadata":[{"lago_id":"7270bde1-b3e4-4368-97db-39a43af3ebf7","key":"k"}]}'
        ));

        // A non-UUID under lago_id (a failed mint, null, garbage) is a diff.
        $diffs = Normalizer::compareJson(
            '{"customer":{"lago_id":"85d10c0d-364e-423a-959c-f6f754435a31"}}',
            '{"customer":{"lago_id":null}}'
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('$.customer.lago_id', $diffs[0]['path']);

        // The same UUID under a NON-minted key stays strict — only lago_id
        // is per-run state.
        $diffs = Normalizer::compareJson(
            '{"id":"85d10c0d-364e-423a-959c-f6f754435a31"}',
            '{"id":"8e82452d-c459-4573-9d8d-c36a3b47ec19"}'
        );

        $this->assertCount(1, $diffs);
    }

    /**
     * lago_subscription_id references a subscription minted by an earlier
     * captured request (fees and billing_periods on a subscription invoice),
     * so each runtime echoes its own fresh id — compared as "a UUID", same
     * rule as lago_id. Everything else under the key stays strict.
     */
    public function test_minted_lago_subscription_ids_compare_as_uuids(): void
    {
        $this->assertSame([], Normalizer::compareJson(
            '{"fees":[{"lago_subscription_id":"c5cdc307-8c07-4521-8a41-c9f93e4f5aea"}]}',
            '{"fees":[{"lago_subscription_id":"36fcec07-b5bc-479d-9329-d0c941db1d17"}]}'
        ));

        $this->assertSame([], Normalizer::compareJson(
            '{"billing_periods":[{"lago_subscription_id":"c5cdc307-8c07-4521-8a41-c9f93e4f5aea"}]}',
            '{"billing_periods":[{"lago_subscription_id":"36fcec07-b5bc-479d-9329-d0c941db1d17"}]}'
        ));

        // A non-UUID under lago_subscription_id (null, garbage) is a diff.
        $diffs = Normalizer::compareJson(
            '{"fees":[{"lago_subscription_id":"c5cdc307-8c07-4521-8a41-c9f93e4f5aea"}]}',
            '{"fees":[{"lago_subscription_id":null}]}'
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('$.fees[0].lago_subscription_id', $diffs[0]['path']);
    }

    public function test_minted_subscription_item_ids_compare_as_uuids(): void
    {
        // A subscription-type fee item echoes the minted subscription id —
        // each runtime its own.
        $this->assertSame([], Normalizer::compareJson(
            '{"fees":[{"item":{"type":"subscription","lago_item_id":"c5cdc307-8c07-4521-8a41-c9f93e4f5aea"}}]}',
            '{"fees":[{"item":{"type":"subscription","lago_item_id":"36fcec07-b5bc-479d-9329-d0c941db1d17"}}]}'
        ));

        // A charge item's lago_item_id is the SEEDED billable metric id and
        // still compares strictly — emitting the wrong id is a diff.
        $diffs = Normalizer::compareJson(
            '{"fees":[{"item":{"type":"charge","lago_item_id":"1a4a0d6e-0000-4000-8000-000000000082"}}]}',
            '{"fees":[{"item":{"type":"charge","lago_item_id":"1a4a0d6e-0000-4000-8000-000000000083"}}]}'
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('$.fees[0].item.lago_item_id', $diffs[0]['path']);
    }

    /**
     * The analytics `month` format is per-endpoint CONTRACT (see
     * Normalizer::STRICT_DATETIME_FIELDS): gross_revenue /
     * overdue_balance / invoiced_usage / invoice_collection render
     * "...Z", mrr renders "...+00:00" — so the ISO8601 Z-vs-offset slack
     * must not apply under that key.
     */
    public function test_analytics_month_compares_strictly_despite_the_iso8601_slack(): void
    {
        // Same instant, wrong per-endpoint format: a diff, not a match.
        $diffs = Normalizer::compareJson(
            '{"mrrs":[{"month":"2025-06-01T00:00:00.000+00:00","amount_cents":5337,"currency":"EUR"}]}',
            '{"mrrs":[{"month":"2025-06-01T00:00:00.000Z","amount_cents":5337,"currency":"EUR"}]}'
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('$.mrrs[0].month', $diffs[0]['path']);

        // Identical formats still match, on every endpoint shape.
        $this->assertSame([], Normalizer::compareJson(
            '{"gross_revenues":[{"month":"2025-06-01T00:00:00.000Z"}]}',
            '{"gross_revenues":[{"month":"2025-06-01T00:00:00.000Z"}]}'
        ));

        $this->assertSame([], Normalizer::compareJson(
            '{"mrrs":[{"month":"2025-06-01T00:00:00.000+00:00"}]}',
            '{"mrrs":[{"month":"2025-06-01T00:00:00.000+00:00"}]}'
        ));

        // A genuinely different month is a diff on the instant, as before.
        $diffs = Normalizer::compareJson(
            '{"invoice_collections":[{"month":"2025-06-01T00:00:00.000Z"}]}',
            '{"invoice_collections":[{"month":"2025-07-01T00:00:00.000Z"}]}'
        );

        $this->assertCount(1, $diffs);
    }

    /**
     * lago_coupon_id references a coupon minted by an earlier captured
     * request (applied_coupons responses), so each runtime echoes its own
     * fresh id — compared as "a UUID", same rule as lago_id. Everything
     * else under the key stays strict.
     */
    public function test_minted_lago_coupon_ids_compare_as_uuids(): void
    {
        $this->assertSame([], Normalizer::compareJson(
            '{"applied_coupon":{"lago_coupon_id":"28e185ac-6160-4bf2-bbd4-b832539adca7"}}',
            '{"applied_coupon":{"lago_coupon_id":"71064ea3-1af4-433c-a77c-3d0d337af727"}}'
        ));

        $this->assertSame([], Normalizer::compareJson(
            '{"applied_coupons":[{"lago_coupon_id":"28e185ac-6160-4bf2-bbd4-b832539adca7"}]}',
            '{"applied_coupons":[{"lago_coupon_id":"71064ea3-1af4-433c-a77c-3d0d337af727"}]}'
        ));

        // A non-UUID under lago_coupon_id (null, garbage) is a diff.
        $diffs = Normalizer::compareJson(
            '{"applied_coupon":{"lago_coupon_id":"28e185ac-6160-4bf2-bbd4-b832539adca7"}}',
            '{"applied_coupon":{"lago_coupon_id":null}}'
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('$.applied_coupon.lago_coupon_id', $diffs[0]['path']);
    }
}

/** Hoist helper: test-local base64url encode (URL-safe, unpadded). */
function base64url_encode(string $value): string
{
    return mb_rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
