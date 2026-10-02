<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

use App\Models\Charge;
use App\Enums\ChargeModel;
use App\Models\FixedCharge;
use App\Models\ChargeFilter;

/**
 * Port of Rails' ChargePropertiesValidation concern
 * (app/models/concerns/charge_properties_validation.rb) — maps a charge
 * model to its property validator and flattens the validator's error codes
 * the way Rails' `validate_charge_model_properties` adds them to
 * `errors[:properties]`.
 */
final class ChargeModelPropertiesValidator
{
    /**
     * Rails: PROPERTIES_VALIDATORS. `custom` and `dynamic` (and any unknown
     * model) fall through to the base validator, like Rails'
     * `validator ||= Charges::Validators::BaseService`.
     *
     * @return list<string> property error codes, empty when valid
     */
    public static function validate(
        string|int|null $chargeModel,
        array $properties,
        Charge|FixedCharge|ChargeFilter $chargeable,
    ): array {
        if ($chargeModel === null || $chargeModel === '') {
            return [];
        }

        if (is_int($chargeModel)) {
            // An out-of-range position fails the enum's inclusion validation
            // on the model; there is no validator to run.
            $label = ChargeModel::tryFrom($chargeModel)?->label();

            if ($label === null) {
                return [];
            }

            $chargeModel = $label;
        }

        $validator = match ($chargeModel) {
            'standard' => StandardService::class,
            'graduated' => GraduatedService::class,
            'package' => PackageService::class,
            'percentage' => PercentageService::class,
            'volume' => VolumeService::class,
            'graduated_percentage' => GraduatedPercentageService::class,
            default => BaseService::class,
        };

        // TODO(port): RATE_PROPERTIES_VALIDATORS (AdjacentVolumeService) are
        // used by catalog rates / overrides (RateProperties) — a later
        // milestone.

        $instance = new $validator($chargeable, $properties);

        if ($instance->valid()) {
            return [];
        }

        return $instance->errorCodes();
    }
}
