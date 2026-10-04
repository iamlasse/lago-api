<?php

declare(strict_types=1);

namespace App\Services\RateOverrides;

use App\Models\RateCard;
use App\Models\RateCardRate;
use App\Models\RateOverride;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\RateCardRates\ModelCompatibility;

use function array_key_exists;

/**
 * Port of Rails' RateOverrides::CreateService
 * (app/services/rate_overrides/create_service.rb) — a phase's own pricing,
 * replacing the card's active rate for that phase.
 */
class CreateService extends BaseService
{
    /**
     * Structural card fields are inherited, never overridden. A caller
     * naming one has misunderstood the contract, not made a typo — reject it.
     */
    private const NOT_OVERRIDABLE_FIELDS = [
        'billing_timing',
        'currency',
        'proration',
        'display_on_invoice',
        'regroup_paid_fees',
        'applied_pricing_unit_code',
    ];

    public function __construct(
        private readonly ?RateCard $rateCard,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_override');
        $rateCard = $this->rateCard;
        $params = $this->params;

        try {
            if ($rateCard === null) {
                return $result->notFoundFailure('rate_card');
            }

            foreach (self::NOT_OVERRIDABLE_FIELDS as $structuralField) {
                if (array_key_exists($structuralField, $params)) {
                    return $result->singleValidationFailure('not_overridable', $structuralField);
                }
            }

            if (($rateCard->applied_pricing_unit_code ?? null) !== null
                && (($params['pricing_unit_conversion_rate'] ?? null) === null || $params['pricing_unit_conversion_rate'] === '')) {
                return $result->singleValidationFailure('value_is_mandatory', 'pricing_unit_conversion_rate');
            }

            // Overrides replace a rate on the same card, so they obey the
            // same model/item/timing compatibility matrix as catalog rates.
            $compatibilityError = ModelCompatibility::errorCode(
                rateModel: $params['rate_model'] ?? null,
                rateCard: $rateCard,
            );

            if ($compatibilityError !== null) {
                return $result->singleValidationFailure($compatibilityError, 'rate_model');
            }

            // Same rule as RateCardRate's min amount: a spend floor true-ups
            // against a closed period, so it only exists on arrears cards.
            if ((int) ($params['min_amount_cents'] ?? 0) > 0 && $rateCard->advance()) {
                return $result->singleValidationFailure('not_allowed_for_billing_timing', 'min_amount_cents');
            }

            // TODO(port): RateProperties::NormalizeRangesService.
            $rateOverride = new RateOverride([
                'organization_id' => $rateCard->organization_id,
                'rate_model' => $params['rate_model'] ?? null,
                'rate_properties' => $params['rate_properties'] ?? [],
                'min_amount_cents' => $params['min_amount_cents'] ?? 0,
                'billing_interval_count' => $params['billing_interval_count'] ?? null,
                'billing_interval_unit' => $params['billing_interval_unit'] ?? null,
                'pricing_unit_conversion_rate' => $params['pricing_unit_conversion_rate'] ?? null,
            ]);

            $errors = $this->validateOverride($rateOverride);
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $rateOverride->save();

            $result->rate_override = $rateOverride;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * RateOverride has no Rails-side validations beyond the DB defaults
     * (the creation path validates through the service); check the shape
     * the serializers and billing rely on.
     *
     * @return array<string, list<string>>
     */
    private function validateOverride(RateOverride $rateOverride): array
    {
        $errors = [];

        // Raw attribute, not getRawOriginal(): the record is unsaved, so
        // there is no snapshot to read back yet.
        $attributes = $rateOverride->getAttributes();

        if (! in_array((string) ($attributes['rate_model'] ?? null), RateCardRate::RATE_MODELS, true)) {
            $errors['rate_model'] = ['value_is_invalid'];
        }

        $unit = $attributes['billing_interval_unit'] ?? null;
        if ($unit !== null && ! in_array((string) $unit, RateCardRate::BILLING_INTERVAL_UNITS, true)) {
            $errors['billing_interval_unit'] = ['value_is_invalid'];
        }

        return $errors;
    }
}
