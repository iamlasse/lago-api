<?php

declare(strict_types=1);

namespace App\Services\Orders\SubscriptionAmendment;

use App\Models\Plan;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\Subscriptions\UpdateUsageThresholdsService;
use App\Services\Subscriptions\CreateService as SubscriptionCreateService;
use App\Services\Orders\SubscriptionCreation\ExecuteService as SubscriptionCreationExecuteService;

/**
 * Port of Rails' Orders::SubscriptionAmendment::ExecuteService
 * (app/services/orders/subscription_amendment/execute_service.rb).
 *
 * An amendment restates one plan on a live subscription, so the payload it
 * carries is a subscription_creation one and the whole mapping is inherited.
 * Only the transition differs: the quoted plan replaces the one the target
 * runs on, instead of a subscription being created.
 */
class ExecuteService extends SubscriptionCreationExecuteService
{
    protected ?Subscription $targetSubscriptionInstance = null;

    protected ?Plan $quotedPlan = null;

    protected ?Plan $catalogPlanInstance = null;

    protected ?array $planItemCache = null;

    protected function createRecords(): array
    {
        $this->validateTargetSubscription();

        // Rails: super.merge(terminated_subscription_ids:) — the inherited
        // mapping runs the amendment (createSubscriptions below) plus the
        // coupons and wallets under the coupon lock.
        return array_merge(
            parent::createRecords(),
            ['terminated_subscription_ids' => $this->terminatedSubscriptionIds()],
        );
    }

    /**
     * Rails overrides create_subscriptions to amend only — the inherited
     * coupons/wallets branches still run through create_records.
     *
     * NOTE: the parent's createSubscriptions() would create a brand-new
     * subscription; the amendment path replaces it, so it is bypassed.
     */
    protected function createSubscriptions(Order $order): array
    {
        return [$this->amendSubscription()];
    }

    /**
     * The plan change carries the target's own binding over, so the wallets
     * created alongside follow the entity that will bill them rather than the
     * customer's default.
     */
    protected function quotedBillingEntityId(Order $order): ?string
    {
        return $this->targetSubscription()?->billing_entity_id;
    }

    /**
     * Subscriptions::CreateService is the entry point the API and the UI
     * already use for a plan change, so the amendment inherits Lago's own
     * semantics in both directions: a raise rotates the subscription now,
     * keeping the external id and the billing anchor, while a reduction keeps
     * the target running and schedules the replacement for the next billing
     * day.
     */
    protected function amendSubscription(): Subscription
    {
        $order = $this->order;
        assert($order !== null);

        $subscription = SubscriptionCreateService::call(
            customer: $order->customer,
            plan: $this->quotedPlan($order),
            params: $this->amendmentParams($order),
        )->raiseIfError()->subscription;

        // CreateService loaded its own instance of the target, so ours is
        // stale.
        $target = $this->targetSubscription();
        assert($target !== null);
        $target->refresh();

        if ($subscription->id === $target->id) {
            $next = $target->nextSubscription();

            $subscription = $next ?? $subscription;
        }

        $this->updateUsageThresholds($subscription);

        return $subscription;
    }

    /** @return array<string, mixed> */
    protected function amendmentParams(Order $order): array
    {
        $payload = (array) (($this->planItems()[0] ?? [])['payload'] ?? []);
        $target = $this->targetSubscription();
        assert($target !== null);

        $params = [
            // Resolves the target through editable_subscriptions, so the plan
            // change always dispatches on it instead of creating a second
            // subscription.
            'subscription_id' => $target->id,
            'external_id' => $target->external_id,
            // Mandatory under the api source, see
            // Orders::SubscriptionCreation::ExecuteService.
            'external_customer_id' => $order->customer->external_id,
            // CreateService strips this to a string, so the target's own name
            // only survives when it is passed back.
            'name' => ($payload['subscriptionName'] ?? null) ?: $target->name,
            // The amendment restates the contract term. A quote carrying no
            // ending date leaves the target's own in place.
            'ending_at' => $this->subscriptionDatetime($order, $payload['endDate'] ?? null),
            'payment_method' => $this->paymentMethodParams($payload),
        ];

        return array_filter($params, fn ($value) => $value !== null);
    }

    /**
     * The negotiated plan is built here rather than passed as plan_overrides
     * because the plan change dispatches on plan ids before prices: a
     * repricing of the same catalog plan would match on id and return the
     * target untouched. An override plan always carries a fresh id, so the
     * comparison reaches the negotiated amount whatever the quote restates.
     */
    protected function quotedPlan(Order $order): Plan
    {
        if ($this->quotedPlan !== null) {
            return $this->quotedPlan;
        }

        $catalogPlan = $this->catalogPlan($order);
        $item = $this->planItem();

        return $this->quotedPlan = \App\Services\Plans\OverrideService::callBang(
            plan: $catalogPlan,
            params: $this->planOverrides($order, $item, $catalogPlan),
        )->plan;
    }

    protected function catalogPlan(Order $order): Plan
    {
        if ($this->catalogPlanInstance !== null) {
            return $this->catalogPlanInstance;
        }

        return $this->catalogPlanInstance = $this->findPlan($order, $this->planItem()['id'] ?? null);
    }

    /**
     * Thresholds ride on the subscription, and CreateService would apply them
     * to whatever the plan change returns, which for a reduction is the
     * target rather than the replacement it scheduled.
     */
    protected function updateUsageThresholds(Subscription $subscription): void
    {
        $thresholds = $this->usageThresholds($this->planItem());

        if ($thresholds === []) {
            return;
        }

        UpdateUsageThresholdsService::callBang(
            subscription: $subscription,
            usageThresholdsParams: $thresholds,
            partial: false,
        );
    }

    /** Empty for a scheduled amendment: the target keeps running until the end of the period. */
    protected function terminatedSubscriptionIds(): array
    {
        $target = $this->targetSubscription();
        assert($target !== null);
        $target->refresh();

        return $target->terminated() ? [$target->id] : [];
    }

    /**
     * Re-runs the approval gate: weeks pass between approval and execution
     * and every state it covers can change in between. A failure here rolls
     * the whole amendment back, leaving the order failed with the reason
     * recorded and the signed order form untouched.
     */
    protected function validateTargetSubscription(): void
    {
        $result = static::makeResult('order');
        $order = $this->order;
        assert($order !== null);

        if (count($this->planItems()) !== 1) {
            $result->singleValidationFailure('single_plan_expected', 'plans')->raiseIfError();
        }

        $target = $this->targetSubscription();

        if ($target === null || $target->customer_id !== $order->customer_id) {
            $result->notFoundFailure('subscription')->raiseIfError();
        }

        assert($target !== null);

        if (! $target->active()) {
            $result->singleValidationFailure('subscription_not_active', 'subscription')->raiseIfError();
        }
    }

    protected function targetSubscription(): ?Subscription
    {
        if ($this->targetSubscriptionInstance !== null) {
            return $this->targetSubscriptionInstance;
        }

        // Order::quote() is a read helper (not a relation) — call it as a method.
        $quote = $this->order?->quote();

        return $this->targetSubscriptionInstance = $quote === null ? null : $quote->subscription()->first();
    }

    /** @return array<string, mixed> */
    protected function planItem(): array
    {
        if ($this->planItemCache !== null) {
            return $this->planItemCache;
        }

        return $this->planItemCache = (array) ($this->planItems()[0] ?? []);
    }
}
