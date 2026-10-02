<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Port of Rails' FeatureFlag registry (app/models/feature_flag.rb) backed by
 * app/config/feature_flags.yaml — the valid flag keys organizations may
 * carry in their `feature_flags` column (Rails: `FeatureFlag.valid?`).
 *
 * The list mirrors Rails' app/config/feature_flags.yaml keys, which are also
 * the frozen schema's FeatureFlagEnum values.
 */
final class FeatureFlag
{
    /**
     * Rails: DEFINITION = YAML.parse_file("app/config/feature_flags.yaml").
     *
     * @var list<string>
     */
    public const DEFINITION = [
        'postgres_enriched_events',
        'wallet_traceability',
        'order_forms',
        'stripe_shared_payment_token',
        'fixed_charge_usage_delta_migration',
        'multi_connection',
        'product_catalog',
        'realtime_usage',
        'skip_credit_invoice_auto_payment_delay',
        'account_tree',
        'x402_payments',
        'x402_reservation_counter_disabled',
    ];

    /** Rails: `FeatureFlag.valid?(flag)` — DEFINITION.key?(flag). */
    public static function valid(string $flag): bool
    {
        return in_array($flag, self::DEFINITION, true);
    }
}
