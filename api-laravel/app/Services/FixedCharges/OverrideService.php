<?php

declare(strict_types=1);

namespace App\Services\FixedCharges;

use App\Models\Plan;
use App\Support\License;
use App\Models\FixedCharge;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Services\ChargeModels\FilterPropertiesService;

/**
 * Port of Rails' FixedCharges::OverrideService
 * (app/services/fixed_charges/override_service.rb) — duplicates a fixed
 * charge onto an override plan with the overridden units / properties.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): FixedCharges::EmitEventsService — fixed charge events are a
 *   later slice; the events result stays empty.
 */
class OverrideService extends BaseService
{
    public function __construct(
        private readonly FixedCharge $fixedCharge,
        /** @var array<string, mixed> */
        private readonly array $params,
        private readonly ?Subscription $subscription = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fixed_charge', 'fixed_charge_events');

        if (! License::premium()) {
            return $result;
        }

        if (($params['charge_model'] ?? null) !== null
            && $this->fixedCharge->charge_model !== $params['charge_model']) {
            return $result->forbiddenFailure('cannot_override_charge_model');
        }

        try {
            DB::transaction(function () use ($result): void {
                $params = $this->params;

                /** @var FixedCharge $newFixedCharge */
                $newFixedCharge = $this->fixedCharge->replicate();

                if (array_key_exists('properties', $params)) {
                    $properties = $params['properties'];

                    $newFixedCharge->properties = blank($properties)
                        ? null
                        : FilterPropertiesService::call(
                            chargeable: $this->fixedCharge,
                            properties: $properties,
                        )->raiseIfError()->properties;
                }

                if (array_key_exists('invoice_display_name', $params)) {
                    $newFixedCharge->invoice_display_name = $params['invoice_display_name'];
                }

                if (array_key_exists('units', $params)) {
                    $newFixedCharge->units = $params['units'];
                }

                $newFixedCharge->parent_id = $this->fixedCharge->id;
                $newFixedCharge->plan_id = $params['plan_id'];

                $newFixedCharge->save();

                $result->fixed_charge_events = [];

                // TODO(port): FixedCharges::EmitEventsService.call!(
                //   fixed_charge: new_fixed_charge, subscription:,
                //   apply_units_immediately: !!params[:apply_units_immediately]).

                if (array_key_exists('tax_codes', $params)) {
                    ApplyTaxesService::call(
                        fixedCharge: $newFixedCharge,
                        taxCodes: (array) $params['tax_codes'],
                    )->raiseIfError();
                }

                $result->fixed_charge = $newFixedCharge;
            });
        } catch (FailedResult $e) {
            return $e->result;
        }

        return $result;
    }
}
