<?php

declare(strict_types=1);

use App\Models\InvoiceSubscription;
use App\Services\Invoices\CreateInvoiceSubscriptionService;

/**
 * Port of spec/services/invoices/create_invoice_subscription_service_spec.rb
 * core scenarios: boundary rows per subscription, and the
 * InvoiceSubscription.matching? double-billing guard.
 */
function cisFixture(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'active',
        'billing_time' => 'calendar',
        'started_at' => '2026-09-15 00:00:00',
        'activated_at' => '2026-09-15 00:00:00',
        'subscription_at' => '2026-09-15 00:00:00',
    ]);
    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => App\Enums\InvoiceStatus::Generating,
    ]);

    return compact('organization', 'customer', 'plan', 'subscription', 'invoice');
}

it('creates one boundary row per subscription', function (): void {
    $f = cisFixture();
    $timestamp = 1791206400; // 2026-10-05 00:00:00 UTC (a Monday, calendar weekly fires)

    $result = CreateInvoiceSubscriptionService::call(
        invoice: $f['invoice'],
        subscriptions: [$f['subscription']],
        timestamp: $timestamp,
        invoicingReason: 'subscription_periodic',
    );

    expect($result->success())->toBeTrue()
        ->and($result->invoice_subscriptions)->toHaveCount(1);

    $row = $result->invoice_subscriptions[0];
    expect($row->invoice_id)->toBe($f['invoice']->id)
        ->and($row->subscription_id)->toBe($f['subscription']->id)
        ->and($row->recurring)->toBeTrue()
        ->and($row->invoicingReasonName())->toBe('subscription_periodic')
        ->and($row->from_datetime)->not->toBeNull()
        ->and($row->to_datetime)->not->toBeNull();
});

it('refuses a duplicated periodic invoice for the same boundaries (matching? guard)', function (): void {
    $f = cisFixture();
    $timestamp = 1791206400;

    $first = CreateInvoiceSubscriptionService::call(
        invoice: $f['invoice'],
        subscriptions: [$f['subscription']],
        timestamp: $timestamp,
        invoicingReason: 'subscription_periodic',
    );
    expect($first->success())->toBeTrue();

    $boundaries = InvoiceSubscription::query()
        ->where('subscription_id', $f['subscription']->id)
        ->first();

    // The guard fires for a SECOND periodic attempt on the same boundaries:
    // seed a matching row as a second invoice would see it.
    $second = CreateInvoiceSubscriptionService::call(
        invoice: $f['invoice'],
        subscriptions: [$f['subscription']],
        timestamp: $timestamp,
        invoicingReason: 'subscription_periodic',
    );

    // matching? is true for these exact boundaries → service failure
    $matches = InvoiceSubscription::matching($f['subscription'], new App\Models\BillingPeriodBoundaries(
        fromDatetime: $boundaries->from_datetime,
        toDatetime: $boundaries->to_datetime,
        chargesFromDatetime: $boundaries->charges_from_datetime,
        chargesToDatetime: $boundaries->charges_to_datetime,
        chargesDuration: null,
        timestamp: $boundaries->timestamp,
    ));

    expect($matches)->toBeTrue()
        ->and($second->failure())->toBeTrue()
        ->and($second->getError()->getCode())->toBe('duplicated_invoices');
});

it('ignores non-recurring boundary rows in the matching guard', function (): void {
    $f = cisFixture();

    // A starting (non-recurring) row does not satisfy the recurring guard.
    InvoiceSubscription::query()->create([
        'invoice_id' => $f['invoice']->id,
        'subscription_id' => $f['subscription']->id,
        'organization_id' => $f['organization']->id,
        'recurring' => false,
        'timestamp' => '2026-09-15 00:00:00',
        'from_datetime' => '2026-09-15 00:00:00',
        'to_datetime' => '2026-10-01 00:00:00',
        'charges_from_datetime' => '2026-09-15 00:00:00',
        'charges_to_datetime' => '2026-10-01 00:00:00',
        'invoicing_reason' => 'subscription_starting',
    ]);

    $matches = InvoiceSubscription::matching($f['subscription'], new App\Models\BillingPeriodBoundaries(
        fromDatetime: '2026-09-15 00:00:00',
        toDatetime: '2026-10-01 00:00:00',
        chargesFromDatetime: '2026-09-15 00:00:00',
        chargesToDatetime: '2026-10-01 00:00:00',
        chargesDuration: null,
        timestamp: '2026-09-15 00:00:00',
    ));

    expect($matches)->toBeFalse();
});
