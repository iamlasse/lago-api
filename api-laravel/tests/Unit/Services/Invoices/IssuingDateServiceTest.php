<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Services\Invoices\IssuingDateService;

/**
 * Port of spec/services/invoices/issuing_date_service_spec.rb — how many
 * days the issuing date shifts from the accounting date, per anchor /
 * adjustment / grace period (customer settings with billing entity
 * fallback).
 */
function issuingCustomer(array $customerOverrides = [], array $entityOverrides = []): Customer
{
    $organization = App\Models\Organization::factory()->create();
    $billingEntity = App\Models\BillingEntity::factory()->create(array_merge([
        'organization_id' => $organization->id,
    ], $entityOverrides));

    return Customer::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ], $customerOverrides));
}

it('returns -1 for current_period_end + keep_anchor', function (): void {
    $customer = issuingCustomer([
        'subscription_invoice_issuing_date_anchor' => 'current_period_end',
        'subscription_invoice_issuing_date_adjustment' => 'keep_anchor',
        'invoice_grace_period' => 3,
    ]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: true))->issuingDateAdjustment())
        ->toBe(-1);
})->group('ledger:svc:Invoices.IssuingDateService');

it('returns the grace period for current_period_end + align_with_finalization_date', function (): void {
    $customer = issuingCustomer([
        'subscription_invoice_issuing_date_anchor' => 'current_period_end',
        'subscription_invoice_issuing_date_adjustment' => 'align_with_finalization_date',
        'invoice_grace_period' => 3,
    ]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: true))->issuingDateAdjustment())
        ->toBe(3);
})->group('ledger:svc:Invoices.IssuingDateService');

it('returns -1 for current_period_end + align_with_finalization_date with no grace period', function (): void {
    $customer = issuingCustomer([
        'subscription_invoice_issuing_date_anchor' => 'current_period_end',
        'subscription_invoice_issuing_date_adjustment' => 'align_with_finalization_date',
        'invoice_grace_period' => 0,
    ]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: true))->issuingDateAdjustment())
        ->toBe(-1);
})->group('ledger:svc:Invoices.IssuingDateService');

it('returns 0 for next_period_start + keep_anchor', function (): void {
    $customer = issuingCustomer([
        'subscription_invoice_issuing_date_anchor' => 'next_period_start',
        'subscription_invoice_issuing_date_adjustment' => 'keep_anchor',
        'invoice_grace_period' => 3,
    ]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: true))->issuingDateAdjustment())
        ->toBe(0);
})->group('ledger:svc:Invoices.IssuingDateService');

it('returns the grace period for next_period_start + align_with_finalization_date', function (): void {
    $customer = issuingCustomer([
        'subscription_invoice_issuing_date_anchor' => 'next_period_start',
        'subscription_invoice_issuing_date_adjustment' => 'align_with_finalization_date',
        'invoice_grace_period' => 3,
    ]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: true))->issuingDateAdjustment())
        ->toBe(3);
})->group('ledger:svc:Invoices.IssuingDateService');

it('uses the billing entity settings when the customer has none', function (): void {
    $customer = issuingCustomer([], [
        'subscription_invoice_issuing_date_anchor' => 'current_period_end',
        'subscription_invoice_issuing_date_adjustment' => 'keep_anchor',
        'invoice_grace_period' => 3,
    ]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: true))->issuingDateAdjustment())
        ->toBe(-1)
        ->and((new IssuingDateService(customerSettings: $customer, recurring: true))->gracePeriod())
        ->toBe(3);
})->group('ledger:svc:Invoices.IssuingDateService');

it('returns the grace period when not recurring', function (): void {
    $customer = issuingCustomer([
        'subscription_invoice_issuing_date_anchor' => 'current_period_end',
        'subscription_invoice_issuing_date_adjustment' => 'keep_anchor',
        'invoice_grace_period' => 3,
    ]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: false))->issuingDateAdjustment())
        ->toBe(3);
})->group('ledger:svc:Invoices.IssuingDateService');

it('returns 0 when no grace period is set anywhere', function (): void {
    // billing_entities.invoice_grace_period is NOT NULL — "unset" is the
    // 0 default; the customer level is where null means "no preference".
    $customer = issuingCustomer(['invoice_grace_period' => null]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: true))->gracePeriod())
        ->toBe(0)
        ->and((new IssuingDateService(customerSettings: $customer, recurring: true))->issuingDateAdjustment())
        ->toBe(0);
})->group('ledger:svc:Invoices.IssuingDateService');

it('reads the grace period from the customer settings', function (): void {
    $customer = issuingCustomer(['invoice_grace_period' => 3]);

    expect((new IssuingDateService(customerSettings: $customer, recurring: true))->gracePeriod())
        ->toBe(3);
})->group('ledger:svc:Invoices.IssuingDateService');
