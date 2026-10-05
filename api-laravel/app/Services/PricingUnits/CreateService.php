<?php

declare(strict_types=1);

namespace App\Services\PricingUnits;

use App\Support\License;
use App\Models\PricingUnit;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' PricingUnits::CreateService
 * (app/services/pricing_units/create_service.rb): premium-gated creation of
 * an organization pricing unit from the name/code/short_name/description
 * params; model validation failures map to record_validation_failure.
 */
class CreateService extends BaseService
{
    public function __construct(private readonly array $args)
    {
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

        $pricingUnit = new PricingUnit([
            'organization_id' => $this->args['organization_id'] ?? null,
            'name' => $this->args['name'] ?? null,
            'code' => $this->args['code'] ?? null,
            'short_name' => $this->args['short_name'] ?? null,
            'description' => $this->args['description'] ?? null,
        ]);

        // Rails: PricingUnit.create! raises RecordInvalid on validation
        // failure -> record_validation_failure!(record:).
        $errors = $pricingUnit->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $pricingUnit->save();

        $result->pricing_unit = $pricingUnit;

        return $result;
    }
}
