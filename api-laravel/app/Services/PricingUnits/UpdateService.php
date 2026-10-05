<?php

declare(strict_types=1);

namespace App\Services\PricingUnits;

use App\Support\License;
use App\Models\PricingUnit;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' PricingUnits::UpdateService
 * (app/services/pricing_units/update_service.rb): premium-gated rename of an
 * organization pricing unit — only name/short_name/description are
 * assignable; model validation failures map to record_validation_failure.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?PricingUnit $pricingUnit,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('pricing_unit');

        // Rails: `return result.forbidden_failure! unless License.premium?`
        // (default code "feature_unavailable").
        if (! License::premium()) {
            return $result->forbiddenFailure();
        }

        if ($this->pricingUnit === null) {
            return $result->notFoundFailure('pricing_unit');
        }

        $pricingUnit = $this->pricingUnit;

        // Rails: params.slice(:name, :short_name, :description).
        foreach (['name', 'short_name', 'description'] as $attribute) {
            if (array_key_exists($attribute, $this->params)) {
                $pricingUnit->{$attribute} = $this->params[$attribute];
            }
        }

        // Rails: update! raises RecordInvalid on validation failure ->
        // record_validation_failure!(record:).
        $errors = $pricingUnit->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $pricingUnit->save();

        $result->pricing_unit = $pricingUnit;

        return $result;
    }
}
