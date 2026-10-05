<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use LogicException;
use App\Serializers\Base\ModelSerializer;
use App\Services\Subscriptions\DatesService;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::SubscriptionSerializer
 * (app/serializers/v1/subscription_serializer.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): entitlements include (Entitlement::SubscriptionEntitlement).
 * - TODO(port): applied_invoice_custom_sections include — empty collection.
 * - TODO(port): activation_rules collection (Subscription::ActivationRule) —
 *   emitted as an empty collection.
 */
class SubscriptionSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $previousSubscription = $this->model->previousSubscription;
        $nextSubscription = $this->model->nextSubscription();

        $datesService = $this->datesService();

        $chargesFrom = $datesService?->chargesFromDatetime();
        $chargesTo = $datesService?->chargesToDatetime();

        $payload = [
            'lago_id' => $this->model->id,
            'external_id' => $this->model->external_id,
            'lago_customer_id' => $this->model->customer_id,
            'external_customer_id' => $this->model->customer->external_id,
            'name' => $this->model->name,
            'plan_code' => $this->model->plan->code,
            'plan_amount_cents' => $this->model->plan->amount_cents,
            'plan_amount_currency' => $this->model->plan->amount_currency,
            'status' => $this->model->statusName(),
            'billing_time' => $this->model->calendar() ? 'calendar' : 'anniversary',
            'subscription_at' => $this->serializeDatetime($this->model->subscription_at),
            'started_at' => $this->serializeDatetimeMillis($this->model->started_at),
            'trial_ended_at' => $this->serializeDatetime($this->model->trial_ended_at),
            'ending_at' => $this->serializeDatetime($this->model->ending_at),
            'terminated_at' => $this->serializeDatetime($this->model->terminated_at),
            'canceled_at' => $this->serializeDatetime($this->model->canceled_at),
            'created_at' => $this->serializeDatetime($this->model->created_at),
            'previous_plan_code' => $previousSubscription?->plan?->code,
            'next_plan_code' => $nextSubscription?->plan?->code,
            'downgrade_plan_date' => $this->serializeDate($this->model->downgradePlanDate()),
            'current_billing_period_started_at' => $chargesFrom?->toIso8601String(),
            'current_billing_period_ending_at' => $chargesTo?->toIso8601String(),
            'on_termination_credit_note' => $this->model->on_termination_credit_note,
            'on_termination_invoice' => $this->model->on_termination_invoice,
            'progressive_billing_disabled' => $this->model->progressive_billing_disabled,
            'consolidate_invoice' => $this->model->consolidate_invoice,
            'purchase_order_number' => $this->model->purchase_order_number,
            'cancellation_reason' => $this->model->cancellation_reason,
            'activated_at' => $this->serializeDatetime($this->model->activated_at),
        ];

        if ($this->include('customer')) {
            $payload['customer'] = $this->customer();
        }

        if ($this->include('entitlements')) {
            $payload['entitlements'] = $this->entitlements();
        }

        $payload = [...$payload, ...$this->paymentMethod()];

        if ($this->include('plan')) {
            $payload['plan'] = $this->plan();
        }

        if ($this->include('usage_threshold')) {
            $payload['usage_threshold'] = ($usageThreshold = $this->options['usage_threshold'] ?? null) !== null
                ? (new UsageThresholdSerializer($usageThreshold))->serialize()
                : null;
        }

        if ($this->include('applicable_usage_thresholds')) {
            $payload['applicable_usage_thresholds'] = $this->model
                ->applicableUsageThresholds()
                ->map(fn ($threshold): array => (new ApplicableUsageThresholdSerializer($threshold))->serialize())
                ->values()
                ->all();
        }

        if ($this->include('applied_invoice_custom_sections')) {
            // TODO(port): Subscription::AppliedInvoiceCustomSection — empty collection.
            $payload['applied_invoice_custom_sections'] = [];
        }

        // Rails: activation_rules — CollectionSerializer over the rules.
        $payload['activation_rules'] = (new \App\Serializers\Base\CollectionSerializer(
            $this->model->activationRules()->get(),
            Subscriptions\ActivationRuleSerializer::class,
            ['collection_name' => 'activation_rules'],
        ))->serialize()['activation_rules'] ?? [];

        $payload['connections'] = $this->model->connectionRouting();

        return $payload;
    }

    // -- Fragments -----------------------------------------------------------------

    protected function customer(): array
    {
        return (new CustomerSerializer($this->model->customer))->serialize();
    }

    /** @return array<string, mixed> */
    protected function plan(): array
    {
        return (new PlanSerializer(
            $this->model->plan,
            ['includes' => $this->includedRelations('plan', [
                'charges',
                'usage_thresholds',
                'applicable_usage_thresholds',
                'taxes',
                'minimum_commitment',
            ])],
        ))->serialize();
    }

    /**
     * NOTE: current billing period is computed with current_usage: true on the
     * billing reference time.
     */
    protected function datesService(): ?DatesService
    {
        if ($this->model->started_at === null && $this->model->plan->interval === null) {
            return null;
        }

        try {
            return DatesService::newInstance($this->model, $this->model->billingReferenceTime(), true);
        } catch (LogicException) {
            // NotImplementedError port — an unusable plan interval yields no
            // current billing period.
            return null;
        }
    }

    /** @return array<string, mixed> */
    protected function paymentMethod(): array
    {
        return [
            'payment_method' => [
                'payment_method_id' => $this->model->payment_method_id,
                'payment_method_type' => $this->model->payment_method_type,
            ],
        ];
    }

    /** Rails: `iso8601(3)` — millisecond precision. */
    protected function serializeDatetimeMillis(?object $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return \Carbon\CarbonImmutable::instance($datetime)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Rails: `iso8601` on a date — midnight of the day. */
    protected function serializeDate(?object $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return \Carbon\CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Rails: `entitlements` include — CollectionSerializer over
     * Entitlement::SubscriptionEntitlement.for_subscription(model) with the
     * V1::Entitlement::SubscriptionEntitlementSerializer.
     *
     * @return list<array<string, mixed>>
     */
    protected function entitlements(): array
    {
        $entitlements = \App\Models\Entitlement\SubscriptionEntitlement::forSubscription($this->model);

        return (new \App\Serializers\Base\CollectionSerializer(
            $entitlements,
            Entitlement\SubscriptionEntitlementSerializer::class,
            ['collection_name' => 'entitlements'],
        ))->serialize()['entitlements'];
    }
}
