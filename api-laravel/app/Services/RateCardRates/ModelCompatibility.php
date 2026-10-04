<?php

declare(strict_types=1);

namespace App\Services\RateCardRates;

use App\Models\RateCard;
use App\Models\BillableMetric;

/**
 * Port of Rails' RateCardRates::ModelCompatibility
 * (app/services/rate_card_rates/model_compatibility.rb) — the v1-parity
 * compatibility matrix between a rate model and the card pricing it
 * (product type, billing timing, proration). Returns the error code to
 * surface on :rate_model, or null when the combination is billable.
 */
final class ModelCompatibility
{
    /** Rails: mirrors FixedCharge::CHARGE_MODELS. */
    private const FIXED_ITEM_RATE_MODELS = ['standard', 'graduated', 'volume'];

    /** Rails: mirrors Charge#validate_prorated. */
    private const PRORATION_ARREARS_MODELS = ['standard', 'volume', 'graduated'];

    private const PRORATION_ADVANCE_MODELS = ['standard'];

    public static function errorCode(?string $rateModel, ?RateCard $rateCard): ?string
    {
        $item = $rateCard?->product;

        if ($rateModel === null || $item === null) {
            return null;
        }

        if ($item->fixed() && ! in_array($rateModel, self::FIXED_ITEM_RATE_MODELS, true)) {
            return 'not_allowed_for_product';
        }

        $metric = $item->billableMetric;

        // Dynamic pricing only works on sum aggregation.
        if ($rateModel === 'dynamic' && ! ($metric?->sumAgg() ?? false)) {
            return 'not_allowed_for_aggregation_type';
        }

        // Percentage models price a monetary amount; a latest aggregation
        // keeps a point-in-time value, not an amount to take a percentage of.
        if (in_array($rateModel, ['percentage', 'graduated_percentage'], true) && ($metric?->latestAgg() ?? false)) {
            return 'not_allowed_for_aggregation_type';
        }

        if ($rateCard->advance()) {
            // Volume needs the full period...
            if ($rateModel === 'volume') {
                return 'not_allowed_for_billing_timing';
            }

            // ...and advance billing needs an aggregation that can be priced
            // per event.
            if ($metric !== null && ! $metric->payableInAdvance()) {
                return 'not_allowed_for_aggregation_type';
            }
        }

        if ($rateCard->proration()) {
            if (! in_array($rateModel, self::prorationModels($rateCard, $metric), true)) {
                return 'not_allowed_with_proration';
            }
        }

        return null;
    }

    /**
     * Rails: `proration_models` — usage proration needs a recurring,
     * non-weighted metric; fixed items prorate by calendar time and follow
     * the same model lists.
     *
     * @return list<string>
     */
    public static function prorationModels(RateCard $rateCard, ?BillableMetric $metric): array
    {
        if ($metric !== null && ($metric->weightedSumAgg() || ! $metric->recurring)) {
            return [];
        }

        return $rateCard->advance()
            ? self::PRORATION_ADVANCE_MODELS
            : self::PRORATION_ARREARS_MODELS;
    }
}
