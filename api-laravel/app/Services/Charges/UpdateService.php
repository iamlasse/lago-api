<?php

declare(strict_types=1);

namespace App\Services\Charges;

use App\Models\Charge;
use App\Enums\ChargeModel;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\ChargeModels\FilterPropertiesService;
use App\Services\ChargeFilters\CreateOrUpdateBatchService;
use App\Services\ChargeModels\BuildDefaultPropertiesService;

/**
 * Port of Rails' Charges::UpdateService (app/services/charges/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): CascadeUpdatable#trigger_cascade — child-plan cascade jobs
 *   (Charges::UpdateChildrenJob) and the filter-cascade dispatcher are a
 *   later milestone; the cascade parameters are accepted and partially
 *   honored (property-only updates in cascade mode).
 * - TODO(port): AppliedPricingUnits::UpdateService — args accepted, ignored.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Charge $charge,
        private readonly array $params,
        private readonly array $cascadeOptions = [],
        private readonly bool $cascadeUpdates = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('charge');

        if ($this->charge === null) {
            return $result->notFoundFailure('charge');
        }

        $charge = $this->charge;
        $params = $this->params;
        $cascade = $this->cascadeOptions['cascade'] ?? false;
        $plan = $charge->plan;

        if ($cascade && $charge->charge_model !== (ChargeModel::fromOption($params['charge_model'] ?? null))) {
            return $result;
        }

        try {
            DB::transaction(function () use ($charge, $params, $cascade, $plan, $result): void {
                if (! $plan->attachedToSubscriptions()) {
                    $chargeModelOption = ChargeModel::fromOption($params['charge_model'] ?? null);

                    if ($chargeModelOption === null && ($params['charge_model'] ?? null) !== null) {
                        $result->validationFailure(['charge_model' => ['value_is_invalid']])->raiseIfError();
                    }

                    $charge->charge_model = $chargeModelOption;
                }

                if (! $cascade) {
                    $charge->invoice_display_name = $params['invoice_display_name'] ?? null;
                }

                if ($cascade && ($params['code'] ?? null) !== null && $params['code'] !== '') {
                    $charge->code = $params['code'];
                }

                // Make sure that pricing group keys and presentation group
                // keys are cascaded even if properties are overridden.
                if ($cascade) {
                    $this->cascadePricingGroupKeys($charge, $params);
                    $this->cascadePresentationGroupKeys($charge, $params);
                }

                if (! $cascade || ($this->cascadeOptions['equal_properties'] ?? false)) {
                    $properties = $this->takeParam($params, 'properties');
                    $properties = (is_array($properties) && $properties !== [])
                        ? $properties
                        : BuildDefaultPropertiesService::call(
                            isset($params['charge_model'])
                                ? $params['charge_model']
                                : $charge->getRawOriginal('charge_model'),
                        )->properties;

                    $charge->properties = FilterPropertiesService::call(
                        chargeable: $charge,
                        properties: $properties,
                    )->properties;
                }

                $acceptsTargetWallet = $this->takeParam($params, 'accepts_target_wallet');

                if ($this->eventsTargetingWalletsEnabled($plan) && $acceptsTargetWallet !== null) {
                    $charge->accepts_target_wallet = $acceptsTargetWallet;
                }

                $this->saveCharge($charge);

                // TODO(port): AppliedPricingUnits::UpdateService.

                $filters = $this->takeParam($params, 'filters');

                if ($filters !== null && ! $cascade) {
                    CreateOrUpdateBatchService::call(
                        charge: $charge,
                        filtersParams: array_map(fn ($filter) => (array) $filter, $filters),
                    )->raiseIfError();
                }

                $result->charge = $charge;

                // In cascade mode it is allowed only to change properties.
                if (! $cascade) {
                    $taxCodes = $this->takeParam($params, 'tax_codes');

                    if ($taxCodes !== null) {
                        ApplyTaxesService::call(
                            charge: $charge,
                            taxCodes: (array) $taxCodes,
                        )->raiseIfError();
                    }

                    // NOTE: charges cannot be edited if plan is attached to a
                    // subscription.
                    if (! $plan->attachedToSubscriptions()) {
                        $invoiceable = $this->takeParam($params, 'invoiceable');
                        $minAmountCents = $this->takeParam($params, 'min_amount_cents');
                        $code = $this->takeParam($params, 'code');

                        if ($this->premium() && $invoiceable !== null) {
                            $charge->invoiceable = $invoiceable;
                        }

                        if ($this->premium()) {
                            $charge->min_amount_cents = $minAmountCents ?? 0;
                        }

                        if ($code !== null && $code !== '') {
                            $charge->code = $code;
                        }

                        // Rails: charge.update!(params) — the remaining
                        // params are re-validated and saved.
                        $this->saveCharge($charge);
                    }
                }
            });

            // TODO(port): trigger_cascade — Charges::UpdateChildrenJob +
            // ChargeFilters::CascadeDispatcher for parent plans.

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $result->singleValidationFailure('value_already_exist', 'code');
        }
    }

    /**
     * Rails mutates `params` in place with `params.delete` — mirror that so
     * the trailing `charge.update!(params)` only sees leftovers.
     *
     * @param  array<string, mixed>  $params
     */
    private function takeParam(array &$params, string $key): mixed
    {
        $value = $params[$key] ?? null;
        unset($params[$key]);

        return $value;
    }

    private function cascadePricingGroupKeys(Charge $charge, array $params): void
    {
        $properties = is_array($params['properties'] ?? null) ? $params['properties'] : [];
        $chargeProperties = is_array($charge->properties) ? $charge->properties : [];

        $pricingGroupKeys = $properties['pricing_group_keys']
            ?? $properties['grouped_by']
            ?? null;

        if ($pricingGroupKeys !== null) {
            $chargeProperties['pricing_group_keys'] = $pricingGroupKeys;
            unset($chargeProperties['grouped_by']);
        } elseif (($chargeProperties['pricing_group_keys'] ?? null) !== null
            || ($chargeProperties['grouped_by'] ?? null) !== null) {
            unset($chargeProperties['pricing_group_keys'], $chargeProperties['grouped_by']);
        }

        $charge->properties = $chargeProperties;
    }

    private function cascadePresentationGroupKeys(Charge $charge, array $params): void
    {
        $properties = is_array($params['properties'] ?? null) ? $params['properties'] : [];
        $chargeProperties = is_array($charge->properties) ? $charge->properties : [];

        $presentationGroupKeys = $properties['presentation_group_keys'] ?? null;

        if ($presentationGroupKeys !== null) {
            $chargeProperties['presentation_group_keys'] = $presentationGroupKeys;
        } elseif (($chargeProperties['presentation_group_keys'] ?? null) !== null) {
            unset($chargeProperties['presentation_group_keys']);
        }

        $charge->properties = $chargeProperties;
    }

    private function saveCharge(Charge $charge): void
    {
        $errors = $charge->validateAttributes();

        if ($errors !== []) {
            static::makeResult()->recordValidationFailure($errors)->raiseIfError();
        }

        $charge->save();
    }

    private function eventsTargetingWalletsEnabled(\App\Models\Plan $plan): bool
    {
        $integrations = $plan->organization->premium_integrations ?? [];

        return $this->premium()
            && in_array('events_targeting_wallets', (array) $integrations, true);
    }
}
