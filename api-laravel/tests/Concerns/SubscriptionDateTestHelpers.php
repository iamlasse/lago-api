<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Invoice;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Models\InvoiceSubscription;

/**
 * Shared fixtures for the Subscriptions::DatesService spec ports — the
 * per-interval suites mirror the Rails spec lets
 * (subscription_at "02 Feb 2021", billing_at "07 Mar 2022" unless overridden).
 */
function datesSubscriptionFor(string $interval, array $overrides = []): Subscription
{
    $plan = Plan::factory()->create([
        'interval' => $interval,
        'pay_in_advance' => $overrides['pay_in_advance'] ?? false,
        'bill_charges_monthly' => $overrides['bill_charges_monthly'] ?? false,
        'bill_fixed_charges_monthly' => $overrides['bill_fixed_charges_monthly'] ?? false,
        'amount_cents' => $overrides['amount_cents'] ?? 100,
    ]);

    $customer = $overrides['customer'] ?? Customer::factory()->create([
        'timezone' => $overrides['timezone'] ?? null,
    ]);

    $subscription = Subscription::factory()->make([
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'subscription_at' => $overrides['subscription_at'] ?? '2021-02-02 00:00:00',
        'billing_time' => $overrides['billing_time'] ?? 'anniversary',
    ]);

    $subscription->started_at = array_key_exists('started_at', $overrides)
        ? $overrides['started_at']
        : ($overrides['subscription_at'] ?? '2021-02-02 00:00:00');

    // Rails trait :pending clears activated_at together with started_at.
    if (array_key_exists('activated_at', $overrides)) {
        $subscription->activated_at = $overrides['activated_at'];
    } elseif ($subscription->started_at === null) {
        $subscription->activated_at = null;
    }

    if (($overrides['external_id'] ?? null) !== null) {
        $subscription->external_id = $overrides['external_id'];
    }

    if (($overrides['previous_subscription_id'] ?? null) !== null) {
        $subscription->previous_subscription_id = $overrides['previous_subscription_id'];
    }

    $subscription->save();

    return $subscription;
}

/** Ports the Rails specs' `.to_s` on a UTC TimeWithZone — second precision. */
function datesUtc(mixed $datetime): string
{
    return CarbonImmutable::instance($datetime)->utc()->format('Y-m-d H:i:s');
}

function datesTerminate(Subscription $subscription, string $terminatedAt): Subscription
{
    // Rails: mark_as_terminated! does `self.terminated_at ||= timestamp` — a
    // second call changes the status but keeps the first termination time.
    $subscription->terminated_at ??= $terminatedAt;

    $subscription->status = 'terminated';
    $subscription->save();

    return $subscription;
}

function datesPreviousInvoiceSubscription(
    Subscription $subscription,
    array $attributes = [],
    ?string $invoiceTimezone = null,
): InvoiceSubscription {
    $invoice = Invoice::query()->create([
        'organization_id' => $subscription->organization_id,
        'customer_id' => $subscription->customer_id,
        'billing_entity_id' => $subscription->customer->billing_entity_id,
        'timezone' => $invoiceTimezone ?? 'UTC',
    ]);

    return InvoiceSubscription::query()->create([
        'subscription_id' => $subscription->id,
        'invoice_id' => $invoice->id,
        'organization_id' => $subscription->organization_id,
        ...$attributes,
    ]);
}
