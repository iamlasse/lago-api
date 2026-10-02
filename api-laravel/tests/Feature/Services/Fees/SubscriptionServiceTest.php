<?php

declare(strict_types=1);

use App\Enums\FeeType;
use App\Models\BillingPeriodBoundaries;
use App\Services\Fees\SubscriptionService;

/**
 * Port of spec/services/fees/subscription_service_spec.rb (proration cases).
 * amount = billed_days * (plan_amount / period_days), Ruby-rounded.
 */
it('prorates the first subscription fee over the billed days', function () {
    $organization = \App\Models\Organization::factory()->create();
    $customer = \App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = \App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'interval' => 'monthly',
        'pay_in_advance' => false,
    ]);
    $subscription = \App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'billing_time' => 'anniversary',
        'started_at' => '2024-07-01 00:00:00',
        'activated_at' => '2024-07-01 00:00:00',
        'subscription_at' => '2024-07-01 00:00:00',
    ]);
    $invoice = \App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => \App\Enums\InvoiceStatus::Generating,
        'currency' => 'EUR',
        'created_at' => '2024-07-04 10:00:00',
    ]);

    // Boundaries of the first period: from subscription start to the billing date.
    $boundaries = new BillingPeriodBoundaries(
        fromDatetime: \Carbon\CarbonImmutable::parse('2024-07-01 00:00:00', 'UTC'),
        toDatetime: \Carbon\CarbonImmutable::parse('2024-07-04 00:00:00', 'UTC'),
        chargesFromDatetime: \Carbon\CarbonImmutable::parse('2024-07-01 00:00:00', 'UTC'),
        chargesToDatetime: \Carbon\CarbonImmutable::parse('2024-07-04 00:00:00', 'UTC'),
        chargesDuration: 31,
        timestamp: \Carbon\CarbonImmutable::parse('2024-07-04 00:00:00', 'UTC'),
    );

    $result = SubscriptionService::call(
        invoice: $invoice,
        subscription: $subscription,
        boundaries: $boundaries,
    );

    expect($result->success())->toBeTrue();

    $fee = $result->fee;

    // Contract: amount = days_to_bill × single_day_price, Ruby-rounded
    // half-away-from-zero. Rails adds 1 second to a day-aligned `to` and
    // ceils — July 1 → July 4 counts 4 days.
    $dateService = \App\Services\Subscriptions\DatesService::newInstance(
        $subscription,
        \Carbon\CarbonImmutable::parse('2024-07-04 00:00:00', 'UTC'),
    );
    $days = \App\Support\Utils\Datetime::dateDiffWithTimezone(
        $boundaries->fromDatetime,
        $boundaries->toDatetime,
        'UTC',
    );

    expect($fee->amount_cents)->toBe(
        \App\Support\MoneyMath::round((string) ($days * $dateService->singleDayPrice())),
    )
        ->and($days)->toBe(4)
        ->and($fee->typeEnum())->toBe(FeeType::Subscription)
        ->and((float) $fee->units)->toBe(1.0)
        ->and($fee->amount_details)->toBe(['plan_amount_cents' => 1000])
        ->and($fee->unit_amount_cents)->toBe($fee->amount_cents);

    expect($invoice->fees()->subscription()->count())->toBe(1);

    // precise_unit_amount = amount in currency units (cents → currency units)
    expect((float) $fee->precise_unit_amount)->toBe($fee->amount_cents / 100);
});

it('returns the existing fee instead of double billing (already_billed?)', function () {
    $organization = \App\Models\Organization::factory()->create();
    $customer = \App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = \App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = \App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);
    $invoice = \App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $existing = \App\Models\Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'fee_type' => FeeType::Subscription,
        'amount_cents' => 555,
    ]);

    $boundaries = new BillingPeriodBoundaries(
        fromDatetime: \Carbon\CarbonImmutable::parse('2024-07-01 00:00:00', 'UTC'),
        toDatetime: \Carbon\CarbonImmutable::parse('2024-08-01 00:00:00', 'UTC'),
        chargesFromDatetime: \Carbon\CarbonImmutable::parse('2024-07-01 00:00:00', 'UTC'),
        chargesToDatetime: \Carbon\CarbonImmutable::parse('2024-08-01 00:00:00', 'UTC'),
        chargesDuration: 31,
        timestamp: \Carbon\CarbonImmutable::parse('2024-07-31 00:00:00', 'UTC'),
    );

    $result = SubscriptionService::call(
        invoice: $invoice,
        subscription: $subscription,
        boundaries: $boundaries,
    );

    expect($result->fee->id)->toBe($existing->id)
        ->and($invoice->fees()->count())->toBe(1);
});
