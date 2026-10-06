<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\FixedCharge;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Services\FixedCharges\ApplyTaxesService;
use App\Services\Subscriptions\Concerns\PlanOverrideConcern;
use App\Services\Subscriptions\FixedChargeUnitsOverrides\WriteService;
use App\Services\Subscriptions\Concerns\FixedChargeUnitsOverrideConcern;

/**
 * Port of Rails' Subscriptions::UpdateOrOverrideFixedChargeService
 * (app/services/subscriptions/update_or_override_fixed_charge_service.rb) —
 * the GraphQL updateSubscriptionFixedCharge body.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): FixedCharges::EmitEventsService — @emitted_fixed_charge_events
 *   stays empty, so the pay-in-advance invoice job (gated on emitted events)
 *   never fires.
 */
class UpdateOrOverrideFixedChargeService extends BaseService
{
    use FixedChargeUnitsOverrideConcern;
    use PlanOverrideConcern;

    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly ?FixedCharge $fixedCharge,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fixed_charge');

        if (! \App\Support\License::premium()) {
            return $result->forbiddenFailure();
        }

        if ($this->subscription === null) {
            return $result->notFoundFailure('subscription');
        }

        if ($this->subscription->incomplete()) {
            return $result->singleValidationFailure('subscription_incomplete');
        }

        if ($this->fixedCharge === null) {
            return $result->notFoundFailure('fixed_charge');
        }

        $subscriptionPlanParentPresent = $this->subscription->plan->parent_id !== null;

        try {
            DB::transaction(function () use ($result, $subscriptionPlanParentPresent): void {
                $result->fixed_charge = $this->unitsOnlyChange($subscriptionPlanParentPresent)
                    ? $this->overrideUnitsOnly()
                    : $this->overrideViaPlan($subscriptionPlanParentPresent);
            });
        } catch (FailedResult $e) {
            return $e->result;
        }

        return $result;
    }

    private function unitsOnlyChange(bool $subscriptionPlanParentPresent): bool
    {
        if ($subscriptionPlanParentPresent) {
            return false;
        }

        return $this->unitsOnlyFixedChargeParams($this->params);
    }

    private function overrideUnitsOnly(): FixedCharge
    {
        $parentFixedCharge = $this->fixedCharge->parent_id !== null
            ? $this->fixedCharge->parent
            : $this->fixedCharge;

        WriteService::callBang(
            subscription: $this->subscription,
            fixedCharge: $parentFixedCharge,
            units: $this->params['units'],
            applyUnitsImmediately: (bool) ($this->params['apply_units_immediately'] ?? false),
        );

        return $parentFixedCharge;
    }

    private function overrideViaPlan(bool $subscriptionPlanParentPresent): FixedCharge
    {
        $parentFixedCharge = $this->fixedCharge->parent_id !== null
            ? $this->fixedCharge->parent
            : $this->fixedCharge;

        $targetPlan = $this->ensurePlanOverride(
            params: $this->promotedPlanOverrideParams($parentFixedCharge, $subscriptionPlanParentPresent),
        );

        return $this->findOrCreateFixedChargeOverride($parentFixedCharge, $targetPlan, $subscriptionPlanParentPresent);

        // TODO(port): the pay-in-advance invoice job — gated on
        // @emitted_fixed_charge_events, which stay empty (see class docblock).
    }

    /**
     * Rails: plan_override_params — the plan clone is seeded with the fixed
     * charge being edited (the customer's change wins).
     */
    private function planOverrideParams(FixedCharge $parentFixedCharge, bool $subscriptionPlanParentPresent): array
    {
        if ($subscriptionPlanParentPresent) {
            return [];
        }

        return ['fixed_charges' => [array_merge($this->params, ['id' => $parentFixedCharge->id])]];
    }

    private function promotedPlanOverrideParams(FixedCharge $parentFixedCharge, bool $subscriptionPlanParentPresent): array
    {
        $baseEntries = $this->planOverrideParams($parentFixedCharge, $subscriptionPlanParentPresent)['fixed_charges'] ?? [];
        $promotedFixedCharges = $this->promoteUnitsOverridesToFixedChargesParams($baseEntries);

        return $promotedFixedCharges !== [] ? ['fixed_charges' => $promotedFixedCharges] : [];
    }

    private function findOrCreateFixedChargeOverride(
        FixedCharge $parentFixedCharge,
        mixed $targetPlan,
        bool $subscriptionPlanParentPresent,
    ): FixedCharge {
        $existingOverride = $targetPlan->fixedCharges()
            ->where('parent_id', $parentFixedCharge->id)
            ->first();

        if (! $subscriptionPlanParentPresent) {
            if ($existingOverride !== null) {
                // The plan clone has already emitted events for this newly
                // created fixed charge.
                return $existingOverride->refresh();
            }

            return $this->createFixedChargeOverride($parentFixedCharge, $targetPlan);
        }

        if ($existingOverride !== null) {
            return $this->updateFixedChargeOverride($existingOverride);
        }

        return $this->createFixedChargeOverride($parentFixedCharge, $targetPlan);
    }

    private function createFixedChargeOverride(FixedCharge $parentFixedCharge, mixed $targetPlan): FixedCharge
    {
        $overrideResult = \App\Services\FixedCharges\OverrideService::callBang(
            fixedCharge: $parentFixedCharge,
            params: array_merge($this->params, ['plan_id' => $targetPlan->id]),
            subscription: $this->subscription,
        );

        return $overrideResult->fixed_charge;
    }

    private function updateFixedChargeOverride(FixedCharge $existingFixedCharge): FixedCharge
    {
        $params = $this->params;

        if (array_key_exists('properties', $params)) {
            $existingFixedCharge->properties = \App\Services\ChargeModels\FilterPropertiesService::call(
                chargeable: $existingFixedCharge,
                properties: ($params['properties'] ?? null) ?: null,
            )->properties;
        }

        if (array_key_exists('invoice_display_name', $params)) {
            $existingFixedCharge->invoice_display_name = $params['invoice_display_name'];
        }

        if (array_key_exists('units', $params)) {
            $existingFixedCharge->units = $params['units'];
        }

        $existingFixedCharge->save();

        // TODO(port): FixedCharges::EmitEventsService.call! — the emitted
        // events stay empty.

        if (array_key_exists('tax_codes', $params)) {
            ApplyTaxesService::call(
                fixedCharge: $existingFixedCharge,
                taxCodes: (array) ($params['tax_codes'] ?? []),
            )->raiseIfError();
        }

        return $existingFixedCharge->refresh();
    }
}
