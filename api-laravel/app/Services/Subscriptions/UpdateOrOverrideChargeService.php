<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Charge;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Services\Charges\ApplyTaxesService;
use App\Services\ChargeFilters\CreateOrUpdateBatchService;
use App\Services\Subscriptions\Concerns\PlanOverrideConcern;
use App\Services\Subscriptions\Concerns\ChargeOverrideConcern;

/**
 * Port of Rails' Subscriptions::UpdateOrOverrideChargeService
 * (app/services/subscriptions/update_or_override_charge_service.rb) — the
 * GraphQL updateSubscriptionCharge body: writes the negotiated charge either
 * in place on the already-overridden plan, or by seeding the override plan
 * with a copy of the catalog charge.
 */
class UpdateOrOverrideChargeService extends BaseService
{
    use ChargeOverrideConcern;
    use PlanOverrideConcern;

    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly ?Charge $charge,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('charge');

        if (! \App\Support\License::premium()) {
            return $result->forbiddenFailure();
        }

        if ($this->subscription === null) {
            return $result->notFoundFailure('subscription');
        }

        if ($this->charge === null) {
            return $result->notFoundFailure('charge');
        }

        try {
            DB::transaction(function () use ($result): void {
                $targetPlan = $this->ensurePlanOverride();
                $result->charge = $this->findOrUpdateChargeOverride($targetPlan);
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (FailedResult $e) {
            // Rails: rescue ActiveRecord::RecordInvalid →
            // result.record_validation_failure! (the FailedResult capture
            // covers the raised record failure in the port).
            return $e->result;
        }

        return $result;
    }

    private function findOrUpdateChargeOverride(mixed $targetPlan): Charge
    {
        // If the resolved charge already lives on the overridden plan, update
        // it in place.
        if ($this->charge->plan_id === $targetPlan->id) {
            return $this->updateChargeOverride($this->charge);
        }

        $parentCharge = $this->findParentCharge();

        $existingOverride = $targetPlan->charges()
            ->where('parent_id', $parentCharge->id)
            ->first();

        if ($existingOverride !== null) {
            return $this->updateChargeOverride($existingOverride);
        }

        $overrideResult = \App\Services\Charges\OverrideService::callBang(
            charge: $parentCharge,
            params: array_merge($this->params, ['plan' => $targetPlan]),
        );

        return $overrideResult->charge;
    }

    private function updateChargeOverride(Charge $existingCharge): Charge
    {
        $params = $this->params;

        if (array_key_exists('properties', $params)) {
            $existingCharge->properties = $params['properties'];
        }

        if (array_key_exists('min_amount_cents', $params)) {
            $existingCharge->min_amount_cents = $params['min_amount_cents'];
        }

        if (array_key_exists('invoice_display_name', $params)) {
            $existingCharge->invoice_display_name = $params['invoice_display_name'];
        }

        $existingCharge->save();

        if (array_key_exists('filters', $params)) {
            CreateOrUpdateBatchService::call(
                charge: $existingCharge,
                filtersParams: (array) ($params['filters'] ?? []),
            )->raiseIfError();
        }

        if (array_key_exists('applied_pricing_unit', $params) && $existingCharge->appliedPricingUnit !== null) {
            // TODO(port): AppliedPricingUnits are a later slice; the override
            // conversion rate is skipped (the relation does not exist yet).
        }

        if (array_key_exists('tax_codes', $params)) {
            ApplyTaxesService::call(
                charge: $existingCharge,
                taxCodes: (array) ($params['tax_codes'] ?? []),
            )->raiseIfError();
        }

        return $existingCharge->refresh();
    }
}
