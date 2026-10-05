<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Plan;
use App\Support\License;
use App\Models\Commitment;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Services\Charges\OverrideService as ChargeOverrideService;
use App\Services\Commitments\OverrideService as CommitmentOverrideService;
use App\Services\FixedCharges\OverrideService as FixedChargeOverrideService;

/**
 * Port of Rails' Plans::OverrideService (app/services/plans/override_service.rb)
 * — builds a child plan overriding specific parts of a parent (amounts,
 * charges, fixed charges, usage thresholds, minimum commitment), used by
 * subscription creation with plan_overrides, plan changes and the quotes /
 * order-forms subscription branches.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): SegmentTrackJob ("plan_created") — analytics later slice.
 */
class OverrideService extends BaseService
{
    public function __construct(
        private readonly Plan $plan,
        /** @var array<string, mixed> */
        private readonly array $params,
        private readonly ?\App\Models\Subscription $subscription = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plan');

        if (! License::premium()) {
            return $result->forbiddenFailure();
        }

        // Per-customer pricing on the catalog is a ContractRateCard.
        // Rails: organization.product_catalog_enabled? — feature flag.
        if (in_array('product_catalog', (array) ($this->plan->organization->feature_flags ?? []), true)) {
            return $result->singleValidationFailure('legacy_billing_disabled', 'plan_overrides');
        }

        try {
            DB::transaction(function () use ($result): void {
                $params = $this->params;

                /** @var Plan $newPlan */
                $newPlan = $this->plan->replicate();
                $newPlan->organization_id = $this->plan->organization_id;

                if (array_key_exists('amount_cents', $params)) {
                    $newPlan->amount_cents = $params['amount_cents'];
                }
                if (array_key_exists('amount_currency', $params)) {
                    $newPlan->amount_currency = $params['amount_currency'];
                }
                if (array_key_exists('description', $params)) {
                    $newPlan->description = $params['description'];
                }
                if (array_key_exists('invoice_display_name', $params)) {
                    $newPlan->invoice_display_name = $params['invoice_display_name'];
                }
                if (array_key_exists('name', $params)) {
                    $newPlan->name = $params['name'];
                }
                if (array_key_exists('trial_period', $params)) {
                    $newPlan->trial_period = $params['trial_period'];
                }

                $newPlan->parent_id = $this->plan->id;
                $newPlan->save();

                if (array_key_exists('tax_codes', $params)) {
                    ApplyTaxesService::call(
                        plan: $newPlan,
                        taxCodes: (array) $params['tax_codes'],
                    )->raiseIfError();
                }

                // Rails indexes the override params by charge id and matches
                // every plan charge against them; unmatched charges are still
                // duplicated as-is.
                $chargesParamsById = [];
                foreach ((array) ($params['charges'] ?? []) as $chargeParams) {
                    $chargeParams = (array) $chargeParams;
                    $chargesParamsById[$chargeParams['id'] ?? null] = $chargeParams;
                }

                $fixedChargesParamsById = [];
                foreach ((array) ($params['fixed_charges'] ?? []) as $fixedChargeParams) {
                    $fixedChargeParams = (array) $fixedChargeParams;
                    $fixedChargesParamsById[$fixedChargeParams['id'] ?? null] = $fixedChargeParams;
                }

                foreach ($this->plan->charges()->get() as $charge) {
                    $chargeParams = array_merge(
                        $chargesParamsById[$charge->id] ?? [],
                        ['plan' => $newPlan],
                    );

                    ChargeOverrideService::call(charge: $charge, params: $chargeParams);
                }

                foreach ($this->plan->fixedCharges()->get() as $fixedCharge) {
                    $fixedChargeParams = array_merge(
                        $fixedChargesParamsById[$fixedCharge->id] ?? [],
                        ['plan_id' => $newPlan->id],
                    );

                    FixedChargeOverrideService::call(
                        fixedCharge: $fixedCharge,
                        params: $fixedChargeParams,
                        subscription: $this->subscription,
                    );
                }

                if (! blank($params['usage_thresholds'] ?? null)
                    && License::premium()
                    && $this->plan->organization->progressiveBillingEnabled()) {
                    \App\Services\UsageThresholds\OverrideService::call(
                        usageThresholdsParams: (array) $params['usage_thresholds'],
                        newPlan: $newPlan,
                    );
                }

                if (! blank($params['minimum_commitment'] ?? null) && License::premium()) {
                    $commitment = new Commitment([
                        'organization_id' => $newPlan->organization_id,
                        'commitment_type' => 0, // minimum_commitment
                    ]);
                    $commitment->plan_id = $newPlan->id;

                    $minimumCommitmentParams = array_merge(
                        (array) $params['minimum_commitment'],
                        ['plan_id' => $newPlan->id],
                    );

                    CommitmentOverrideService::call(
                        commitment: $commitment,
                        params: $minimumCommitmentParams,
                    )->raiseIfError();
                }

                $result->plan = $newPlan;

                // TODO(port): SegmentTrackJob("plan_created") — analytics slice.
            });
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }

        return $result;
    }
}
