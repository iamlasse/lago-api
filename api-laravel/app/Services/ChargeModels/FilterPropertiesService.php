<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Models\Charge;
use App\Models\FixedCharge;
use App\Services\BaseResult;
use App\Services\ChargeModels\FilterProperties\ChargeService;
use App\Services\ChargeModels\FilterProperties\FixedChargeService;

/**
 * Port of Rails' ChargeModels::FilterPropertiesService
 * (app/services/charge_models/filter_properties_service.rb).
 */
class FilterPropertiesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Charge|FixedCharge $chargeable,
        private readonly mixed $properties = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('properties');

        $filterService = match (true) {
            $this->chargeable instanceof Charge => new ChargeService($this->chargeable, $this->properties),
            $this->chargeable instanceof FixedCharge => new FixedChargeService($this->chargeable, $this->properties),
        };

        $result->properties = $filterService->callOrFail()->properties;

        return $result;
    }
}
