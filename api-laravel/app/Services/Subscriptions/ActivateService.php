<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use Carbon\CarbonInterface;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Subscriptions::ActivateService
 * (app/services/subscriptions/activate_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Subscriptions::ActivationRules::{Evaluate,Apply}Service and
 *   the payment gating branch (gate_subscription) — Subscription::ActivationRule
 *   is not ported; no subscription gates meanwhile.
 * - TODO(port): EmitFixedChargeEventsService.
 * - TODO(port): BillSubscriptionJob / BillNonInvoiceableFeesJob /
 *   Invoices::CreatePayInAdvanceFixedChargesJob /
 *   ActivationRules::BillCurrentPeriodJob — the billing side (M1 task 9).
 * - TODO(port): SendWebhookJob emissions + ActivityLog + Hubspot sync.
 */
class ActivateService extends BaseService
{
    protected CarbonInterface $timestamp;

    public function __construct(
        protected Subscription $subscription,
        ?CarbonInterface $timestamp = null,
    ) {
        parent::__construct();
        $this->timestamp = $timestamp ?? now();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription');

        if ($this->subscription->active()) {
            $result->subscription = $this->subscription;

            return $result;
        }

        if ($this->subscription->gated()) {
            $result->subscription = $this->subscription;

            return $result;
        }

        DB::transaction(function (): void {
            // Rails: ActivationRules::EvaluateService.call! if pending?
            if ($this->subscription->pending()) {
                ActivationRules\EvaluateService::callBang(
                    subscription: $this->subscription,
                );
            }

            if ($this->subscription->pending() && $this->subscription->pendingRules()) {
                $this->gateSubscription();
            } else {
                $this->activateSubscription();
            }
        });

        $result->subscription = $this->subscription;

        return $result;
    }

    /**
     * Rails: `gate_subscription` — the subscription waits on its activation
     * rules: it moves to incomplete and stays there until the rules resolve
     * (ResolveSubscriptionStatusService finishes the job).
     */
    protected function gateSubscription(): void
    {
        $this->subscription->markAsIncomplete($this->timestamp);
        $this->subscription->save();

        // TODO(port): emit_fixed_charge_events (EmitFixedChargeEventsService).

        // Rails after_commit:
        if ($this->subscription->paymentGated()) {
            // TODO(port): bill_subscription(skip_charges: true) — the billing
            // side (BillSubscriptionJob wiring for this path) is task 9.
        }

        \App\Jobs\SendWebhookJob::performLater('subscription.incomplete', $this->subscription);

        // TODO(port): Utils::ActivityLog.produce(subscription,
        // "subscription.incomplete").
    }

    protected function activateSubscription(): void
    {
        // Rails: return if incomplete? && activation_rules.rejected.exists?
        if ($this->subscription->incomplete()
            && $this->subscription->activationRules()->rejected()->exists()) {
            return;
        }

        if ($this->upgrade()) {
            $this->activateForUpgrade();
        } elseif ($this->downgrade()) {
            $this->activateForDowngrade();
        } else {
            $this->activateStandalone();
        }
    }

    protected function activateForUpgrade(): void
    {
        $fromIncomplete = $this->subscription->incomplete();

        $billedDuringGating = $fromIncomplete
            && $this->subscription->activationRules()->where('type', 'payment')->exists();

        $previousSubscription = $this->subscription->previousSubscription;

        TerminateService::call(
            subscription: $previousSubscription,
            upgrade: true,
        );

        $this->subscription->markAsActive($this->timestamp);
        $this->subscription->save();

        $billableSubscriptions = [$previousSubscription];

        if (! $fromIncomplete) {
            // TODO(port): EmitFixedChargeEventsService (started_at + 1.second)
        }

        if (! $billedDuringGating) {
            $trialFree = ! $this->subscription->inTrialPeriod();

            if ($this->subscription->fixedCharges()->where('fixed_charges.pay_in_advance', true)->exists()
                || ($this->subscription->plan->pay_in_advance && $trialFree)) {
                $billableSubscriptions[] = $this->subscription;
            }
        }

        // TODO(port): enqueue_gating_catch_up_jobs when from_incomplete.

        // TODO(port): bill_rotation_subscriptions — groups billable
        // subscriptions by purchase order number and enqueues
        // BillSubscriptionJob(subscriptions, billing_at = Time.current + 1s,
        // invoicing_reason: :upgrading) + BillNonInvoiceableFeesJob.

        // TODO(port): SendWebhookJob "subscription.started" + ActivityLog + Hubspot.
    }

    protected function activateForDowngrade(): void
    {
        $fromIncomplete = $this->subscription->incomplete();

        $billedDuringGating = $fromIncomplete
            && $this->subscription->activationRules()->where('type', 'payment')->exists();

        $previousSubscription = $this->subscription->previousSubscription;

        $previousSubscription->markAsTerminated($this->timestamp);
        $previousSubscription->save();

        $this->subscription->markAsActive($this->timestamp);
        $this->subscription->save();

        $billableSubscriptions = [$previousSubscription];

        if (! $fromIncomplete) {
            // TODO(port): EmitFixedChargeEventsService (started_at + 1.second)
        }

        if (! $billedDuringGating) {
            if ($this->subscription->fixedCharges()->where('fixed_charges.pay_in_advance', true)->exists()
                || $this->subscription->plan->pay_in_advance) {
                $billableSubscriptions[] = $this->subscription;
            }
        }

        // TODO(port): enqueue_gating_catch_up_jobs when from_incomplete.

        // TODO(port): SendWebhookJob "subscription.terminated" on previous
        // subscription + ActivityLog + Hubspot.
        // TODO(port): bill_rotation_subscriptions(billable_subscriptions,
        //   billing_at: timestamp) — BillSubscriptionJob/BillNonInvoiceableFeesJob.

        // TODO(port): SendWebhookJob "subscription.started" + ActivityLog + Hubspot.
    }

    protected function activateStandalone(): void
    {
        $fromIncomplete = $this->subscription->incomplete();

        $this->subscription->markAsActive($this->timestamp);
        $this->subscription->save();

        if (! $fromIncomplete) {
            // TODO(port): EmitFixedChargeEventsService (started_at + 1.second)
        }

        // Rails after_commit:
        // - from_incomplete: bill_subscription when activation_rules.payment.none?
        //   (TODO(port)) + enqueue_gating_catch_up_jobs (TODO(port))
        // - else: bill_subscription(skip_charges: true) (TODO(port))
        // TODO(port): SendWebhookJob "subscription.started" + ActivityLog + Hubspot.
    }

    // -- Branching -----------------------------------------------------------------

    protected function upgrade(): bool
    {
        $previous = $this->subscription->previousSubscription;

        if ($previous === null) {
            return false;
        }

        if ($this->subscription->plan->id === $previous->plan->id) {
            return false;
        }

        return $this->subscription->plan->yearlyAmountCents() >= $previous->plan->yearlyAmountCents();
    }

    protected function downgrade(): bool
    {
        $previous = $this->subscription->previousSubscription;

        if ($previous === null) {
            return false;
        }

        if ($this->subscription->plan->id === $previous->plan->id) {
            return false;
        }

        return $this->subscription->plan->yearlyAmountCents() < $previous->plan->yearlyAmountCents();
    }
}
