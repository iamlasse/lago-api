<?php

declare(strict_types=1);

namespace App\Services\Charges;

use App\Models\Plan;
use App\Models\Charge;
use App\Support\License;
use App\Models\ChargeFilter;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ChargeFilterValue;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Services\ChargeFilters\CreateOrUpdateBatchService;

/**
 * Port of Rails' Charges::OverrideService
 * (app/services/charges/override_service.rb) — duplicates a catalog charge
 * onto an override plan, carrying over the negotiated properties.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): AppliedPricingUnits::CreateService — applied pricing units
 *   are a later slice; an overridden charge loses its applied unit.
 */
class OverrideService extends BaseService
{
    public function __construct(
        private readonly Charge $charge,
        /** @var array<string, mixed> */
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('charge');

        if (! License::premium()) {
            return $result;
        }

        try {
            DB::transaction(function () use ($result): void {
                $params = $this->params;

                /** @var Charge $newCharge */
                $newCharge = $this->charge->replicate();

                $newCharge->organization_id = $params['plan'] instanceof Plan
                    ? $params['plan']->organization_id
                    : $newCharge->organization_id;

                if ($params['plan'] instanceof Plan) {
                    $newCharge->plan_id = $params['plan']->id;
                }

                $newCharge->billable_metric_id = $this->charge->billable_metric_id;

                if (array_key_exists('properties', $params)) {
                    $newCharge->properties = $params['properties'];
                }

                if (array_key_exists('min_amount_cents', $params)) {
                    $newCharge->min_amount_cents = $params['min_amount_cents'];
                }

                if (array_key_exists('invoice_display_name', $params)) {
                    $newCharge->invoice_display_name = $params['invoice_display_name'];
                }

                $newCharge->parent_id = $this->charge->id;

                if (! ($params['plan'] instanceof Plan)) {
                    $newCharge->plan_id = $params['plan_id'] ?? $newCharge->plan_id;
                }

                $newCharge->save();

                // Rails: c.filters = charge.filters.map(&:dup) — the override
                // carries the catalog charge's filters with fresh value rows.
                foreach ($this->charge->filters as $filter) {
                    /** @var ChargeFilter $newFilter */
                    $newFilter = $filter->replicate();
                    $newFilter->charge_id = $newCharge->id;
                    $newFilter->save();

                    foreach ($filter->values as $value) {
                        /** @var ChargeFilterValue $newValue */
                        $newValue = $value->replicate();
                        $newValue->charge_filter_id = $newFilter->id;
                        $newValue->save();
                    }
                }

                if (array_key_exists('filters', $params)) {
                    CreateOrUpdateBatchService::call(
                        charge: $newCharge,
                        filtersParams: (array) $params['filters'],
                    )->raiseIfError();
                }

                if (array_key_exists('tax_codes', $params)) {
                    ApplyTaxesService::call(
                        charge: $newCharge,
                        taxCodes: (array) $params['tax_codes'],
                    )->raiseIfError();
                }

                $result->charge = $newCharge;
            });
        } catch (FailedResult $e) {
            return $e->result;
        }

        return $result;
    }
}
