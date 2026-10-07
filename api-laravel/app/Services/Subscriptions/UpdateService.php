<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Plan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\InvoiceCustomSections\AttachToResourceService;
use App\Support\Utils\Datetime;
use App\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::UpdateService
 * (app/services/subscriptions/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): plan_overrides — License-gated premium; Plans::OverrideService
 *   / Plans::UpdateService-overrides and the fixed-charge units override
 *   promotion are deferred at the marked hook.
 * - TODO(port): Subscriptions::ActivationRules::ApplyService.
 * - TODO(port): InvoiceCustomSections::AttachToResourceService.
 * - TODO(port): BillingObjectConnections::AttachToResourceService.
 * - TODO(port): Invoices::CreatePayInAdvanceFixedChargesJob +
 *   BillSubscriptionJob — the billing side (M1 task 9).
 * - TODO(port): SendWebhookJob emissions + ActivityLog + Hubspot sync.
 */
class UpdateService extends BaseService
{
    protected ?Subscription $subscription;

    /** @var array<string, mixed> */
    protected array $params;

    public function __construct(?Subscription $subscription, array $params)
    {
        parent::__construct();

        $this->subscription = $subscription;
        $this->params = $params;
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription', 'payment_method');

        $subscription = $this->subscription;

        if ($subscription === null) {
            return $result->notFoundFailure('subscription');
        }

        if ($subscription->incomplete()) {
            return $result->notAllowedFailure('subscription_incomplete');
        }

        if ($this->purchaseOrderNumberChangeAttempted($subscription)
            && ! $subscription->pending()
            && ! $subscription->active()
        ) {
            return $result->notAllowedFailure('purchase_order_number_not_editable');
        }

        $params = $this->params;

        $valid = (new ValidateService($result, [
            'customer' => $subscription->customer,
            'plan' => $subscription->plan,
            'subscription_at' => array_key_exists('subscription_at', $params)
                ? $params['subscription_at']
                : $subscription->subscription_at,
            'ending_at' => $params['ending_at'] ?? null,
            'on_termination_credit_note' => $params['on_termination_credit_note'] ?? null,
            'on_termination_invoice' => $params['on_termination_invoice'] ?? null,
            'payment_method' => $params['payment_method'] ?? null,
            'connections' => $params['connections'] ?? null,
            'activation_rules' => $params['activation_rules'] ?? null,
            'subscription_type' => 'update',
            'subscription' => $subscription,
            'consolidate_invoice' => $params['consolidate_invoice'] ?? null,
            'consolidate_invoice_provided' => array_key_exists('consolidate_invoice', $params),
        ]))->valid();

        if (! $valid) {
            return $result;
        }

        // TODO(port): Remove check we stop supporting `plan_overrides.usage_thresholds`
        if (! blank($params['usage_thresholds'] ?? null)
            && ! blank(is_array($params['plan_overrides'] ?? null) ? ($params['plan_overrides']['usage_thresholds'] ?? null) : null)
        ) {
            return $result->validationFailure([
                'plan_overrides.usage_thresholds' => ['incompatible_params'],
                'usage_thresholds' => ['incompatible_params'],
            ]);
        }

        if (! $this->premium() && array_key_exists('plan_overrides', $params)) {
            return $result->forbiddenFailure();
        }

        // Rails: plan_overrides on a product-catalog organization is refused.
        if (array_key_exists('plan_overrides', $params)
            && in_array('product_catalog', (array) ($subscription->plan->organization->feature_flags ?? []), true)
        ) {
            return $result->singleValidationFailure('legacy_billing_disabled', 'plan_overrides');
        }

        // UpdateUsageThresholdsService.call! — WIRED (usage-monitoring slice).
        if (array_key_exists('usage_thresholds', $params)) {
            UpdateUsageThresholdsService::callBang(
                subscription: $this->subscription,
                usageThresholdsParams: (array) $params['usage_thresholds'],
                partial: false,
            );
        }

        if (! blank($params['connections'] ?? null)
            && ! $this->organizationFlagEnabled($subscription->organization, 'multi_connection')
        ) {
            return $result->forbiddenFailure();
        }

        DB::transaction(function () use ($result, $subscription, $params): void {
            if (array_key_exists('name', $params)) {
                $subscription->name = $params['name'];
            }

            if (array_key_exists('ending_at', $params)) {
                $subscription->ending_at = $this->toCarbon($params['ending_at']);
            }

            if (array_key_exists('purchase_order_number', $params)) {
                $subscription->purchase_order_number = $params['purchase_order_number'];
            }

            if (array_key_exists('progressive_billing_disabled', $params)) {
                $subscription->progressive_billing_disabled = $params['progressive_billing_disabled'];
            }

            if (array_key_exists('consolidate_invoice', $params)) {
                $subscription->consolidate_invoice = filter_var($params['consolidate_invoice'], FILTER_VALIDATE_BOOLEAN);
            }

            if ($subscription->plan->pay_in_advance && array_key_exists('on_termination_credit_note', $params)) {
                $subscription->on_termination_credit_note = $params['on_termination_credit_note'];
            }

            if (array_key_exists('on_termination_invoice', $params)) {
                $subscription->on_termination_invoice = $params['on_termination_invoice'];
            }

            $paymentMethod = $params['payment_method'] ?? null;
            if (is_array($paymentMethod)) {
                if (array_key_exists('payment_method_type', $paymentMethod)) {
                    $subscription->payment_method_type = $paymentMethod['payment_method_type'];
                }

                if (array_key_exists('payment_method_id', $paymentMethod)) {
                    $subscription->payment_method_id = $paymentMethod['payment_method_id'];
                }
            }

            // TODO(port): resolve_billing_entity — billing_entity_id /
            // billing_entity_code re-resolution (BillingEntities::ResolveService)
            // is wired by the controller-facing slice.

            // Rails: units_only_plan_overrides_change? →
            //   apply_units_only_plan_overrides; elsif params.key?(:plan_overrides)
            //   → subscription.plan = handle_plan_override.plan.
            // TODO(port): the units-only branch writes
            //   subscription_fixed_charge_units_overrides rows
            //   (Subscriptions::FixedChargeUnitsOverrides::WriteService — the
            //   fixed-charge-units-override slice). A units-only request is
            //   detected and validated, but the write is deferred meanwhile.
            if ($this->unitsOnlyPlanOverridesChange($subscription)) {
                foreach ((array) ($params['plan_overrides']['fixed_charges'] ?? []) as $entry) {
                    $entry = (array) $entry;
                    $fixedCharge = $subscription->plan->fixedCharges()->whereKey($entry['id'] ?? null)->first();

                    if ($fixedCharge === null) {
                        $result->notFoundFailure('fixed_charge')->raiseIfError();
                    }

                    // TODO(port): FixedChargeUnitsOverrides::WriteService.call!.
                }
            } elseif (array_key_exists('plan_overrides', $params)) {
                $subscription->plan_id = $this->handlePlanOverride($subscription)->id;
            }

            // Rails: params.key?(:activation_rules) && !subscription_at_changing_to_past?
            //   → ActivationRules::ApplyService.call!.
            if (array_key_exists('activation_rules', $params) && ! $this->subscriptionAtChangingToPast($subscription)) {
                ActivationRules\ApplyService::callBang(
                    subscription: $subscription,
                    activationRules: (array) ($params['activation_rules'] ?? []),
                );
            }

            if ($subscription->startingInTheFuture() && array_key_exists('subscription_at', $params)) {
                $subscription->subscription_at = $this->toCarbon($params['subscription_at']);

                $this->processSubscriptionAtChange($subscription);
            } else {
                $errors = $subscription->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $subscription->save();

                if ($subscription->active()
                    && $subscription->fixedCharges()->where('fixed_charges.pay_in_advance', true)->exists()
                    && $subscription->isDirty('plan_id')
                ) {
                    // TODO(port): Invoices::CreatePayInAdvanceFixedChargesJob
                    //   .perform_after_commit(subscription, Time.current.to_i)
                }

                // TODO(port): SendWebhookJob "subscription.updated" + Hubspot sync.
            }

            AttachToResourceService::call(resource: $subscription, params: $this->params);

            // TODO(port): BillingObjectConnections::AttachToResourceService.
        });

        $result->subscription = $subscription;

        return $result;
    }

    // -- Helpers ---------------------------------------------------------------------

    protected function payInAdvance(Subscription $subscription): bool
    {
        return (bool) $subscription->plan->pay_in_advance;
    }

    /**
     * The attribute is normalized on assignment (see HasPurchaseOrderNumber),
     * so a caller resending the stored value — or a blank or padded equivalent
     * of it — is not changing it.
     */
    protected function purchaseOrderNumberChangeAttempted(Subscription $subscription): bool
    {
        if (! array_key_exists('purchase_order_number', $this->params)) {
            return false;
        }

        $raw = $this->params['purchase_order_number'];
        $normalized = is_string($raw) ? (mb_trim($raw) === '' ? null : mb_trim($raw)) : $raw;

        return $normalized !== $subscription->purchase_order_number;
    }

    protected function subscriptionAtChangingToPast(Subscription $subscription): bool
    {
        if (! $subscription->startingInTheFuture()) {
            return false;
        }

        if (! array_key_exists('subscription_at', $this->params)) {
            return false;
        }

        $parsed = Datetime::parseIso8601($this->params['subscription_at']);

        return $parsed !== null
            && $parsed->startOfDay()->lt(CarbonImmutable::now()->startOfDay());
    }

    protected function processSubscriptionAtChange(Subscription $subscription): void
    {
        $subscriptionAt = CarbonImmutable::instance($subscription->subscription_at)->utc();
        $today = CarbonImmutable::now()->startOfDay();

        $isFuture = $subscriptionAt->startOfDay()->gt($today);
        $isToday = $subscriptionAt->startOfDay()->equalTo($today);

        if ($isFuture || ($isToday && $this->activationRulesPresent($subscription))) {
            $subscription->status = SubscriptionStatus::Pending->value;
            // Rails: pending! persists — carries the new subscription_at.
            $subscription->save();

            return;
        }

        // Rails: subscription_at_changing_to_past? clears the rules —
        // ActivationRules::ApplyService with [] when rules exist.
        if ($subscription->activationRules()->exists()) {
            ActivationRules\ApplyService::callBang(
                subscription: $subscription,
                activationRules: [],
            );
        }

        $subscription->markAsActive($subscription->subscription_at);
        $subscription->save();

        // TODO(port): EmitFixedChargeEventsService (started_at + 1.second).

        if ($isToday) {
            if ($this->payInAdvance($subscription)) {
                // TODO(port): BillSubscriptionJob.perform_after_commit(
                //   [subscription], Time.current.to_i,
                //   invoicing_reason: :subscription_starting)
            } elseif ($subscription->fixedCharges()->where('fixed_charges.pay_in_advance', true)->exists()) {
                // TODO(port): Invoices::CreatePayInAdvanceFixedChargesJob
                //   .perform_after_commit(subscription, started_at + 1.second)
            }
        }

        // NOTE: Reaching this point means the subscription went from pending to
        // active, so it emits `subscription.started` like every other
        // activation path, and `subscription.updated` like every other edit
        // going through this service.
        // TODO(port): SendWebhookJob "subscription.started" (notify_started).
        // TODO(port): SendWebhookJob "subscription.updated" (notify_updated).
    }

    /** Rails: `subscription.activation_rules.any?`. */
    protected function activationRulesPresent(Subscription $subscription): bool
    {
        return $subscription->activationRules()->exists();
    }

    /**
     * Rails: `units_only_plan_overrides_change?` (the detection concern): the
     * plan is not already an override, plan_overrides is present and carries
     * only units-touched fixed_charges entries.
     */
    protected function unitsOnlyPlanOverridesChange(Subscription $subscription): bool
    {
        if ($subscription->plan->parent_id !== null) {
            return false;
        }

        if (! array_key_exists('plan_overrides', $this->params)) {
            return false;
        }

        return $this->unitsOnlyFixedChargesPlanOverrides($this->params['plan_overrides']);
    }

    /** Rails: FixedChargeUnitsOverrideDetectionConcern#units_only_fixed_charges_plan_overrides?. */
    protected function unitsOnlyFixedChargesPlanOverrides(mixed $planOverrides): bool
    {
        $planOverrides = is_array($planOverrides) ? $planOverrides : null;

        if ($planOverrides === null || array_keys($planOverrides) !== ['fixed_charges']) {
            return false;
        }

        $fixedCharges = $planOverrides['fixed_charges'];

        if (! is_array($fixedCharges) || $fixedCharges === []) {
            return false;
        }

        foreach ($fixedCharges as $entry) {
            if (! $this->unitsOnlyFixedChargesEntry($entry)) {
                return false;
            }
        }

        return true;
    }

    /** Rails: PLAN_OVERRIDES_FIXED_CHARGE_ALLOWED_KEYS. */
    protected function unitsOnlyFixedChargesEntry(mixed $entry): bool
    {
        $entry = $this->normalizeHash($entry);

        if ($entry === null) {
            return false;
        }

        if (! array_key_exists('id', $entry) || ! array_key_exists('units', $entry)) {
            return false;
        }

        // Rails: (entry.keys - PLAN_OVERRIDES_FIXED_CHARGE_ALLOWED_KEYS).empty?
        return array_diff(array_keys($entry), ['id', 'units', 'apply_units_immediately']) === [];
    }

    /** Rails: FixedChargeUnitsOverrideDetectionConcern#normalize_hash. */
    protected function normalizeHash(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Rails: `handle_plan_override` — a subscription already running an
     * override plan updates that child in place, otherwise the current plan
     * is overridden with the params.
     */
    protected function handlePlanOverride(Subscription $subscription): Plan
    {
        $currentPlan = $subscription->plan;

        if ($currentPlan->parent_id !== null) {
            // Rails: Plans::UpdateService with plan_update_params_with_full_
            // fixed_charges — the override params expanded with every fixed
            // charge of the plan so unspecified ones are restated verbatim.
            // TODO(port): the full-fixed-charges expansion (it pins
            //   charge_model/properties/units of every fixed charge, which
            //   the child-plan edit path needs) — deferred with the units
            //   override slice; the child plan is updated with the raw
            //   override params meanwhile.
            return \App\Services\Plans\UpdateService::callBang(
                plan: $currentPlan,
                params: (array) $this->params['plan_overrides'],
            )->plan;
        }

        $overrideResult = \App\Services\Plans\OverrideService::callBang(
            plan: $currentPlan,
            params: (array) $this->params['plan_overrides'],
            subscription: $subscription,
        );

        $subscription->plan_id = $overrideResult->plan->id;
        $subscription->save();

        return $overrideResult->plan;
    }

    protected function organizationFlagEnabled(object $organization, string $flag): bool
    {
        return in_array($flag, (array) ($organization->feature_flags ?? []), true);
    }

    protected function toCarbon(mixed $value): ?CarbonInterface
    {
        return Datetime::parseIso8601($value);
    }
}
