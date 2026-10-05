<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\Invoice;
use App\Models\Commitment;
use App\Enums\PlanInterval;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Charges\GenerateCodeService;
use App\Services\Charges\CreateService as ChargesCreateService;
use App\Services\Charges\UpdateService as ChargesUpdateService;
use App\Services\Charges\DestroyService as ChargesDestroyService;
use App\Services\FixedCharges\CreateService as FixedChargesCreateService;
use App\Services\FixedCharges\UpdateService as FixedChargesUpdateService;
use App\Services\FixedCharges\DestroyService as FixedChargesDestroyService;
use App\Services\Commitments\ApplyTaxesService as CommitmentsApplyTaxesService;
use App\Services\FixedCharges\GenerateCodeService as FixedChargesGenerateCodeService;

use function array_key_exists;

/**
 * Port of Rails' Plans::UpdateService (app/services/plans/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): plan metadata (Metadata::UpdateItemService).
 * - TODO(port): the cascade jobs — Plans::UpdateAmountJob,
 *   Charges::{Create,Update,Destroy}ChildrenJob, ChargeFilters::CascadeDispatcher,
 *   FixedCharges::CascadePlanUpdateJob (parent-plan children are a later
 *   milestone); `cascade_updates` is accepted but no-op.
 * - TODO(port): Subscriptions::PlanUpgradeService (task 8) — the pending-
 *   subscription upgrade branch logs and skips.
 * - TODO(port): Invoices::CreateAllPayInAdvanceFixedChargesJob billing
 *   trigger and SendWebhookJob emission.
 */
class UpdateService extends BaseService
{
    protected int $timestamp;

    public function __construct(
        private readonly ?Plan $plan,
        private readonly array $params,
        private readonly bool $partialMetadata = false,
        private readonly bool $sendWebhook = true,
    ) {
        parent::__construct();

        $this->timestamp = now()->getTimestamp();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plan');

        if ($this->plan === null) {
            return $result->notFoundFailure('plan');
        }

        $plan = $this->plan;
        $params = $this->params;

        if ($this->productCatalogEnabled($plan->organization)) {
            $legacyField = $this->findLegacyField($params);

            if ($legacyField !== null) {
                return $result->singleValidationFailure('legacy_billing_disabled', $legacyField);
            }
        }

        $oldAmountCents = $plan->amount_cents;

        if (array_key_exists('name', $params)) {
            $plan->name = $params['name'];
        }
        if (array_key_exists('invoice_display_name', $params)) {
            $plan->invoice_display_name = $params['invoice_display_name'];
        }
        if (array_key_exists('description', $params)) {
            $plan->description = $params['description'];
        }
        if (array_key_exists('amount_cents', $params)) {
            $plan->amount_cents = $params['amount_cents'];
        }

        // NOTE: If plan is attached to subscriptions the editable attributes
        //       are: name, invoice_display_name, description, amount_cents.
        if (! $plan->attachedToSubscriptions()) {
            if (array_key_exists('code', $params)) {
                $plan->code = $params['code'];
            }
            if (array_key_exists('interval', $params)) {
                $intervalOption = PlanInterval::fromOption($params['interval']);

                if ($intervalOption === null && $params['interval'] !== null) {
                    // Unknown enum name fails the inclusion validation.
                    $plan->interval = -1;
                } else {
                    $plan->interval = $intervalOption;
                }
            }
            if (array_key_exists('pay_in_advance', $params)) {
                $plan->pay_in_advance = $params['pay_in_advance'];
            }
            if (array_key_exists('amount_currency', $params)) {
                $plan->amount_currency = $params['amount_currency'];
            }
            if (array_key_exists('trial_period', $params)) {
                $plan->trial_period = $params['trial_period'];
            }
            $plan->bill_charges_monthly = $this->billChargesMonthly($params);
            $plan->bill_fixed_charges_monthly = $this->billFixedChargesMonthly($params);
        }

        $chargeablesValidationResult = ChargeablesValidationService::call(
            organization: $plan->organization,
            charges: $this->present($params['charges'] ?? null),
            fixedCharges: $this->present($params['fixed_charges'] ?? null),
        );

        if ($chargeablesValidationResult->failure()) {
            return $chargeablesValidationResult;
        }

        try {
            DB::transaction(function () use ($plan, $params, $result, $oldAmountCents): void {
                $this->savePlan($plan);

                // TODO(port): update_metadata! — Metadata::UpdateItemService.

                if (array_key_exists('tax_codes', $params) && $params['tax_codes'] !== null) {
                    ApplyTaxesService::call(
                        plan: $plan,
                        taxCodes: (array) $params['tax_codes'],
                    )->raiseIfError();
                }

                if (array_key_exists('charges', $params) && $params['charges'] !== null) {
                    $this->processCharges($plan, $result, $params['charges']);
                }

                if (array_key_exists('fixed_charges', $params) && $params['fixed_charges'] !== null) {
                    $this->processFixedCharges($plan, $result, $params['fixed_charges']);
                }

                // Plans::UpdateUsageThresholdsService — WIRED
                // (usage-monitoring slice; premium progressive billing).
                if (array_key_exists('usage_thresholds', $params) && $this->premium()) {
                    UpdateUsageThresholdsService::call(
                        plan: $plan,
                        usageThresholdsParams: (array) $params['usage_thresholds'],
                    );
                }

                if (($params['minimum_commitment'] ?? null) !== null && $this->premium()) {
                    $this->processMinimumCommitment($plan, (array) $params['minimum_commitment']);
                }

                if ($oldAmountCents !== $plan->amount_cents) {
                    $this->processDowngradedSubscriptions($plan);
                    $this->processPendingSubscriptions($plan);
                }
            });

            // TODO(port): cascade_subscription_fee_update —
            // Plans::UpdateAmountJob on child plans.

            $this->flagDraftInvoicesForRefresh($plan);

            // TODO(port): SendWebhookJob.perform_after_commit("plan.updated", plan).

            $result->plan = $plan->refresh();

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function findLegacyField(array $params): ?string
    {
        foreach (CreateService::LEGACY_PRICING_FIELDS as $field) {
            if (array_key_exists($field, $params)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function billChargesMonthly(array $params): ?bool
    {
        if (! $this->billableMonthly($params)) {
            return null;
        }

        return $params['bill_charges_monthly'] ?? false;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function billFixedChargesMonthly(array $params): ?bool
    {
        if (! $this->billableMonthly($params)) {
            return null;
        }

        return $params['bill_fixed_charges_monthly'] ?? false;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function billableMonthly(array $params): bool
    {
        $interval = PlanInterval::fromOption($params['interval'] ?? null);

        return $interval === PlanInterval::Yearly->value || $interval === PlanInterval::Semiannual->value;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function processMinimumCommitment(Plan $plan, array $params): void
    {
        $minimumCommitment = null;

        if ($params !== []) {
            $minimumCommitment = $plan->minimumCommitment()->first()
                ?? new Commitment([
                    'organization_id' => $plan->organization_id,
                    'plan_id' => $plan->id,
                    'commitment_type' => Commitment::MINIMUM_COMMITMENT,
                ]);

            if (array_key_exists('amount_cents', $params)) {
                $minimumCommitment->amount_cents = $params['amount_cents'];
            }
            if (array_key_exists('invoice_display_name', $params)) {
                $minimumCommitment->invoice_display_name = $params['invoice_display_name'];
            }

            $minimumCommitment->save();
        } elseif ($plan->minimumCommitment()->first() !== null) {
            $plan->minimumCommitment()->first()->delete();
        }

        if ((array_key_exists('tax_codes', $params) && $params['tax_codes'] !== null)) {
            CommitmentsApplyTaxesService::call(
                commitment: $minimumCommitment,
                taxCodes: (array) $params['tax_codes'],
            )->raiseIfError();
        }
    }

    /**
     * @param  iterable<array<string, mixed>>  $paramsCharges
     */
    private function processCharges(Plan $plan, BaseResult $result, iterable $paramsCharges): void
    {
        $createdChargesIds = [];
        $hashCharges = [];

        foreach ($paramsCharges as $c) {
            $hashCharges[] = (array) $c;
        }

        foreach ($hashCharges as $payloadCharge) {
            $charge = $plan->charges()->where('id', $payloadCharge['id'] ?? null)->first();

            if ($charge !== null) {
                // TODO(port): cascade_charge_update — Charges::UpdateChildrenJob.

                ChargesUpdateService::call(charge: $charge, params: $payloadCharge)->raiseIfError();

                continue;
            }

            $createChargeResult = ChargesCreateService::call(
                plan: $plan,
                params: $this->chargeParamsWithCode($plan, $payloadCharge),
            )->raiseIfError();

            // TODO(port): cascade_charge_creation — Charges::CreateChildrenJob.

            $createdChargesIds[] = $createChargeResult->charge->id;
        }

        // NOTE: Delete charges that are no more linked to the plan.
        $this->sanitizeCharges($plan, $hashCharges, $createdChargesIds);
    }

    /**
     * @param  list<array<string, mixed>>  $argsCharges
     * @param  list<string>  $createdChargesIds
     */
    private function sanitizeCharges(Plan $plan, array $argsCharges, array $createdChargesIds): void
    {
        $argsChargesIds = array_values(array_filter(array_map(
            fn ($c) => $c['id'] ?? null,
            $argsCharges,
        )));

        $chargesIds = array_diff(
            $plan->charges()->pluck('id')->all(),
            $argsChargesIds,
            $createdChargesIds,
        );

        foreach ($plan->charges()->whereIn('id', $chargesIds)->get() as $charge) {
            // TODO(port): cascade_charge_removal — Charges::DestroyChildrenJob.

            ChargesDestroyService::call(charge: $charge);
        }
    }

    /**
     * @param  iterable<array<string, mixed>>  $paramsFixedCharges
     */
    private function processFixedCharges(Plan $plan, BaseResult $result, iterable $paramsFixedCharges): void
    {
        $createdFixedChargesIds = [];
        $hashFixedCharges = [];

        foreach ($paramsFixedCharges as $c) {
            $hashFixedCharges[] = (array) $c;
        }

        foreach ($hashFixedCharges as $payloadFixedCharge) {
            $fixedCharge = $plan->fixedCharges()->where('id', $payloadFixedCharge['id'] ?? null)->first();

            if ($fixedCharge !== null) {
                // TODO(port): cascade payload (FixedCharges::CascadePlanUpdateJob).

                FixedChargesUpdateService::call(
                    fixedCharge: $fixedCharge,
                    params: $payloadFixedCharge,
                    timestamp: $this->timestamp,
                    triggerBilling: false,
                )->raiseIfError();

                continue;
            }

            $createFixedChargeResult = FixedChargesCreateService::call(
                plan: $plan,
                params: $this->fixedChargeParamsWithCode($plan, $payloadFixedCharge),
                timestamp: $this->timestamp,
            )->raiseIfError();

            $createdFixedChargesIds[] = $createFixedChargeResult->fixed_charge->id;
        }

        // NOTE: Delete fixed_charges that are no more linked to the plan.
        $this->sanitizeFixedCharges($plan, $hashFixedCharges, $createdFixedChargesIds);

        // TODO(port): trigger_pay_in_advance_billing —
        // Invoices::CreateAllPayInAdvanceFixedChargesJob when the plan has
        // pay-in-advance fixed charges.

        // TODO(port): cascade_fixed_charges — FixedCharges::CascadePlanUpdateJob.
    }

    /**
     * @param  list<array<string, mixed>>  $argsFixedCharges
     * @param  list<string>  $createdFixedChargesIds
     */
    private function sanitizeFixedCharges(Plan $plan, array $argsFixedCharges, array $createdFixedChargesIds): void
    {
        $argsFixedChargesIds = array_values(array_filter(array_map(
            fn ($c) => $c['id'] ?? null,
            $argsFixedCharges,
        )));

        $fixedChargesIds = array_diff(
            $plan->fixedCharges()->pluck('id')->all(),
            $argsFixedChargesIds,
            $createdFixedChargesIds,
        );

        foreach ($plan->fixedCharges()->whereIn('id', $fixedChargesIds)->get() as $fixedCharge) {
            // TODO(port): cascade_fixed_charge_removal.

            FixedChargesDestroyService::call(fixedCharge: $fixedCharge);
        }
    }

    /**
     * @param  array<string, mixed>  $chargeParams
     * @return array<string, mixed>
     */
    private function chargeParamsWithCode(Plan $plan, array $chargeParams): array
    {
        if (($chargeParams['code'] ?? null) !== null && $chargeParams['code'] !== '') {
            return $chargeParams;
        }

        $billableMetric = \App\Models\BillableMetric::query()
            ->where('organization_id', $plan->organization_id)
            ->where('id', $chargeParams['billable_metric_id'] ?? null)
            ->first();

        if ($billableMetric === null) {
            return $chargeParams;
        }

        $chargeParams['code'] = GenerateCodeService::call(
            plan: $plan,
            billableMetric: $billableMetric,
        )->code;

        return $chargeParams;
    }

    /**
     * @param  array<string, mixed>  $fixedChargeParams
     * @return array<string, mixed>
     */
    private function fixedChargeParamsWithCode(Plan $plan, array $fixedChargeParams): array
    {
        if (($fixedChargeParams['code'] ?? null) !== null && $fixedChargeParams['code'] !== '') {
            return $fixedChargeParams;
        }

        $addOn = \App\Models\AddOn::query()
            ->where('organization_id', $plan->organization_id)
            ->where('id', $fixedChargeParams['add_on_id'] ?? null)
            ->first();

        if ($addOn === null) {
            return $fixedChargeParams;
        }

        $fixedChargeParams['code'] = FixedChargesGenerateCodeService::call(
            plan: $plan,
            addOn: $addOn,
        )->code;

        return $fixedChargeParams;
    }

    /**
     * Rails: flag_draft_invoices_for_refresh — mark the organization's draft
     * invoices attached to this plan (via invoice_subscriptions) as
     * ready_to_be_refreshed.
     */
    private function flagDraftInvoicesForRefresh(Plan $plan): void
    {
        Invoice::query()
            ->from('invoices')
            ->join('invoice_subscriptions', 'invoice_subscriptions.invoice_id', '=', 'invoices.id')
            ->join('subscriptions', 'subscriptions.id', '=', 'invoice_subscriptions.subscription_id')
            ->where('invoices.organization_id', $plan->organization_id)
            ->where('subscriptions.plan_id', $plan->id)
            ->where('invoices.status', 0) // Invoice.draft (STATUS: draft=0)
            ->distinct()
            ->update(['ready_to_be_refreshed' => true]);
    }

    /**
     * NOTE: We should remove pending subscriptions if plan has been
     * downgraded but amount cents became less than downgraded value. This
     * pending subscription is not relevant in this case and downgrade should
     * be ignored.
     */
    private function processDowngradedSubscriptions(Plan $plan): void
    {
        $activeSubscriptionIds = $plan->subscriptions()->where('status', 1)->pluck('id'); // active

        if ($activeSubscriptionIds === []) {
            return;
        }

        $pending = Subscription::query()
            ->whereIn('previous_subscription_id', $activeSubscriptionIds)
            ->where('status', 0); // pending

        foreach ($pending->get() as $subscription) {
            if ($plan->amount_cents < (Plan::find($subscription->plan_id)?->amount_cents ?? 0)) {
                // Rails: sub.mark_as_canceled! (status canceled + canceled_at).
                $subscription->canceled_at ??= now();
                $subscription->status = 3; // canceled
                $subscription->save();
            }
        }
    }

    /**
     * NOTE: If new plan yearly amount is higher than its value before the
     * update and there are pending subscriptions for the plan, this is a
     * plan upgrade: the old subscription must be terminated and billed, and
     * the new subscription activated immediately.
     */
    private function processPendingSubscriptions(Plan $plan): void
    {
        $pending = Subscription::query()
            ->where('plan_id', $plan->id)
            ->where('status', 0) // pending
            ->get();

        foreach ($pending as $subscription) {
            if ($subscription->previous_subscription_id === null) {
                continue;
            }

            // Rails: subscription.previous_subscription.plan.yearly_amount_cents.
            $previousPlan = Plan::find(
                Subscription::find($subscription->previous_subscription_id)?->plan_id,
            );

            if ($previousPlan !== null
                && $plan->yearlyAmountCents() >= $previousPlan->yearlyAmountCents()) {
                // TODO(port): Subscriptions::PlanUpgradeService (M1 task 8) —
                // the upgrade emission point; the upgrade is skipped until
                // that service exists.
            }
        }
    }

    /**
     * Rails: `organization.product_catalog_enabled?` — feature flag, not
     * license-gated.
     */
    private function productCatalogEnabled(\App\Models\Organization $organization): bool
    {
        return in_array('product_catalog', (array) ($organization->feature_flags ?? []), true);
    }

    private function savePlan(Plan $plan): void
    {
        $errors = $plan->validateAttributes();

        if ($errors !== []) {
            static::makeResult()->recordValidationFailure($errors)->raiseIfError();
        }

        $plan->save();
    }

    private function present(mixed $value): array
    {
        return (is_array($value) && $value !== []) ? $value : [];
    }
}
