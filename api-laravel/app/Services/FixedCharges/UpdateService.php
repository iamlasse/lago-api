<?php

declare(strict_types=1);

namespace App\Services\FixedCharges;

use App\Models\FixedCharge;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\ChargeModels\FilterPropertiesService;
use App\Services\ChargeModels\BuildDefaultPropertiesService;

/**
 * Port of Rails' FixedCharges::UpdateService
 * (app/services/fixed_charges/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): FixedCharges::EmitEventsService on units change and the
 *   Invoices::CreateAllPayInAdvanceFixedChargesJob billing trigger (billing
 *   is a later milestone); the emission point is marked below.
 * - TODO(port): CascadeUpdatable#trigger_cascade (FixedCharges cascade jobs).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?FixedCharge $fixedCharge,
        private readonly array $params,
        private readonly int $timestamp = 0,
        private readonly array $cascadeOptions = [],
        private readonly bool $triggerBilling = true,
        private readonly bool $cascadeUpdates = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fixed_charge');

        if ($this->fixedCharge === null) {
            return $result->notFoundFailure('fixed_charge');
        }

        $fixedCharge = $this->fixedCharge;
        $params = $this->params;
        $cascade = $this->cascadeOptions['cascade'] ?? false;
        $plan = $fixedCharge->plan;

        if ($cascade && $fixedCharge->getRawOriginal('charge_model') !== ($params['charge_model'] ?? null)) {
            return $result;
        }

        try {
            DB::transaction(function () use ($fixedCharge, $params, $cascade, $plan, $result): void {
                // Note: when updating a fixed_charge, we can't update
                // pay_in_advance and prorated.
                if (! $plan->attachedToSubscriptions()) {
                    $fixedCharge->charge_model = $params['charge_model'] ?? null;
                }

                if (! $cascade) {
                    $fixedCharge->invoice_display_name = $params['invoice_display_name'] ?? null;
                }

                if ($cascade && ($params['code'] ?? null) !== null && $params['code'] !== '') {
                    $fixedCharge->code = $params['code'];
                }

                if (! $cascade || ($this->cascadeOptions['equal_properties'] ?? false)) {
                    $fixedCharge->units = $params['units'] ?? null;

                    $propertiesParam = $this->takeParam($params, 'properties');

                    $properties = (is_array($propertiesParam) && $propertiesParam !== [])
                        ? $propertiesParam
                        : BuildDefaultPropertiesService::call($params['charge_model'] ?? null)
                            ->properties;

                    $fixedCharge->properties = FilterPropertiesService::call(
                        chargeable: $fixedCharge,
                        properties: $properties,
                    )->properties;
                } else {
                    $this->takeParam($params, 'properties');
                }

                $this->saveFixedCharge($fixedCharge);

                $result->fixed_charge = $fixedCharge;

                if ($fixedCharge->wasChanged('units')) {
                    // TODO(port): FixedCharges::EmitEventsService +
                    // Invoices::CreateAllPayInAdvanceFixedChargesJob when
                    // params[:apply_units_immediately] and the charge is
                    // pay_in_advance — the billing trigger point.
                }

                if (! $cascade) {
                    $taxCodes = $this->takeParam($params, 'tax_codes');

                    if ($taxCodes !== null && $taxCodes !== false) {
                        ApplyTaxesService::call(
                            fixedCharge: $fixedCharge,
                            taxCodes: (array) $taxCodes,
                        )->raiseIfError();
                    }

                    // NOTE: structural fields cannot be edited if plan is
                    // attached to a subscription.
                    if (! $plan->attachedToSubscriptions()) {
                        $code = $this->takeParam($params, 'code');

                        if ($code !== null && $code !== '') {
                            $fixedCharge->code = $code;
                        }

                        $this->saveFixedCharge($fixedCharge);
                    }
                }
            });

            // TODO(port): trigger_cascade (FixedCharges cascade jobs).

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $result->singleValidationFailure('value_already_exist', 'code');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function takeParam(array &$params, string $key): mixed
    {
        $value = $params[$key] ?? null;
        unset($params[$key]);

        return $value;
    }

    private function saveFixedCharge(FixedCharge $fixedCharge): void
    {
        $errors = $fixedCharge->validateAttributes();

        if ($errors !== []) {
            static::makeResult()->recordValidationFailure($errors)->raiseIfError();
        }

        $fixedCharge->save();
    }
}
