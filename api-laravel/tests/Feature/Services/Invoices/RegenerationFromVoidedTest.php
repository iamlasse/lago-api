<?php

declare(strict_types=1);

use App\Enums\FeeType;
use App\Enums\InvoicePaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\InvoiceSubscription;
use App\Services\Invoices\BuildRegenerationPreviewService;
use App\Services\Invoices\RegenerateFromVoidedService;

/**
 * Ports of Rails' spec/services/invoices/regenerate_from_voided_service_spec.rb
 * (core scenarios) and build_regeneration_preview_service_spec.rb.
 *
 * Ledger rows: svc:Invoices.RegenerateFromVoidedService,
 * svc:Invoices.BuildRegenerationPreviewService.
 *
 * TODO(port) branches not covered: credit-note credit / prepaid-credit legs
 * (Credits::CreditNoteService / AppliedPrepaidCreditsService unported).
 */
function regenFixture(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create([
        'organization_id' => $organization->id,
        'net_payment_term' => 30,
    ]);
    $plan = App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 0,
        'amount_currency' => 'EUR',
        'interval' => 'monthly',
        'pay_in_advance' => false,
    ]);
    $metric = App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'aggregation_type' => 1,
        'recurring' => false,
        'field_name' => 'value',
    ]);
    $charge = App\Models\Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
        'invoiceable' => true,
        'pay_in_advance' => false,
    ]);
    $subscription = App\Models\Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'terminated',
        'external_id' => 'sub-regen-1',
        'billing_time' => 'calendar',
        'started_at' => '2026-09-01 00:00:00',
        'activated_at' => '2026-09-01 00:00:00',
        'subscription_at' => '2026-09-01 00:00:00',
    ]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::Voided,
        'invoice_type' => InvoiceType::Subscription,
        'currency' => 'EUR',
        'net_payment_term' => 30,
    ]);

    InvoiceSubscription::query()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'recurring' => true,
        'timestamp' => '2026-09-01 00:00:00',
        'from_datetime' => '2026-09-01 00:00:00',
        'to_datetime' => '2026-10-01 00:00:00',
        'charges_from_datetime' => '2026-09-01 00:00:00',
        'charges_to_datetime' => '2026-10-01 00:00:00',
        'invoicing_reason' => 'subscription_periodic',
    ]);

    $fee = App\Models\Fee::factory()->create([
        'organization_id' => $organization->id,
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'charge_id' => $charge->id,
        'invoiceable_type' => 'Charge',
        'invoiceable_id' => $charge->id,
        'amount_currency' => 'EUR',
        'fee_type' => FeeType::Charge,
        'units' => '10',
        'unit_amount_cents' => 100,
        'precise_unit_amount' => '1',
        'amount_cents' => 1000,
        'precise_amount_cents' => '1000',
        'payment_status' => App\Enums\FeePaymentStatus::Pending,
        'properties' => [
            'timestamp' => '2026-09-01T00:00:00+00:00',
            'from_datetime' => '2026-09-01T00:00:00+00:00',
            'to_datetime' => '2026-10-01T00:00:00+00:00',
            'charges_from_datetime' => '2026-09-01T00:00:00+00:00',
            'charges_to_datetime' => '2026-10-01T00:00:00+00:00',
        ],
        'taxes_amount_cents' => 0,
    ]);

    return compact('organization', 'customer', 'plan', 'metric', 'charge', 'subscription', 'invoice', 'fee');
}

function regenFeesParams(array $f, array $overrides = []): array
{
    return [array_merge([
        'id' => $f['fee']->id,
        'subscription_id' => $f['subscription']->id,
        'charge_id' => $f['charge']->id,
        'invoice_display_name' => 'new-dis-name',
        'units' => 10,
        'unit_amount_cents' => 50.50,
    ], $overrides)];
}

it('regenerates a voided invoice with adjusted display name, units and unit amount', function (): void {
    $f = regenFixture();

    $result = RegenerateFromVoidedService::call(
        voidedInvoice: $f['invoice'],
        feesParams: regenFeesParams($f),
    );

    expect($result->success())->toBeTrue();

    $invoice = $result->invoice;

    expect($invoice->id)->not->toBe($f['invoice']->id)
        ->and($invoice->voided_invoice_id)->toBe($f['invoice']->id)
        ->and($invoice->status)->toBe(InvoiceStatus::Finalized)
        ->and($invoice->invoice_type)->toBe(InvoiceType::Subscription)
        ->and($invoice->currency)->toBe('EUR')
        ->and($invoice->customer_id)->toBe($f['customer']->id);

    $regeneratedFee = $invoice->fees()->where('fee_type', FeeType::Charge)->first();

    expect($regeneratedFee)->not->toBeNull()
        ->and($regeneratedFee->invoice_display_name)->toBe('new-dis-name')
        ->and(\App\Support\MoneyMath::compare((string) $regeneratedFee->units, '10'))->toBe(0)
        ->and((int) $regeneratedFee->unit_amount_cents)->toBe(5050)
        ->and((int) $regeneratedFee->amount_cents)->toBe(10 * 5050);

    $invoice->refresh();

    expect((int) $invoice->fees_amount_cents)->toBe(10 * 5050)
        ->and((int) $invoice->total_amount_cents)->toBe(10 * 5050)
        ->and($invoice->payment_status)->toBe(InvoicePaymentStatus::Pending)
        // Rails: issuing_date = today in the customer timezone; due date adds
        // the net payment term.
        ->and($invoice->issuing_date->toDateString())->toBe(now('UTC')->toDateString())
        ->and($invoice->payment_due_date->toDateString())->toBe(now('UTC')->addDays(30)->toDateString());
})->group('ledger:svc:Invoices.RegenerateFromVoidedService');

it('duplicates the voided invoice subscriptions onto the regenerated invoice', function (): void {
    $f = regenFixture();

    $result = RegenerateFromVoidedService::call(
        voidedInvoice: $f['invoice'],
        feesParams: regenFeesParams($f),
    );

    $invoice = $result->invoice;

    $subs = $invoice->invoiceSubscriptions()->get();

    expect($result->success())->toBeTrue()
        ->and($subs)->toHaveCount(1)
        ->and($subs[0]->subscription_id)->toBe($f['subscription']->id)
        // The voided invoice's subscription now points at the regenerated one.
        ->and($f['invoice']->invoiceSubscriptions()->first()->refresh()->regenerated_invoice_id)
            ->toBe($invoice->id);
})->group('ledger:svc:Invoices.RegenerateFromVoidedService');

it('inherits the voided invoice purchase order number and writes it to search terms', function (): void {
    $f = regenFixture();
    $f['invoice']->update(['purchase_order_number' => 'PO-ORIGINAL']);

    $result = RegenerateFromVoidedService::call(
        voidedInvoice: $f['invoice'],
        feesParams: regenFeesParams($f),
    );

    $invoice = $result->invoice;
    $invoice->refresh();

    expect($result->success())->toBeTrue()
        ->and($invoice->purchase_order_number)->toBe('PO-ORIGINAL')
        ->and((string) $invoice->search_terms)->toContain('PO-ORIGINAL')
        ->and((string) $invoice->search_terms)->toContain((string) $invoice->number);
})->group('ledger:svc:Invoices.RegenerateFromVoidedService');

it('normalizes an explicitly provided purchase order number', function (): void {
    $f = regenFixture();

    $result = RegenerateFromVoidedService::call(
        voidedInvoice: $f['invoice'],
        feesParams: regenFeesParams($f),
        purchaseOrderNumber: '  PO-EDITED  ',
    );

    expect($result->success())->toBeTrue()
        ->and($result->invoice->purchase_order_number)->toBe('PO-EDITED');
})->group('ledger:svc:Invoices.RegenerateFromVoidedService');

it('answers not found when the voided invoice is missing', function (): void {
    $result = RegenerateFromVoidedService::call(
        voidedInvoice: null,
        feesParams: [],
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('invoice_not_found');
})->group('ledger:svc:Invoices.RegenerateFromVoidedService');

it('builds an in-memory regeneration preview without persisting anything', function (): void {
    $f = regenFixture();

    $invoicesCount = Invoice::query()->count();
    $feesCount = App\Models\Fee::query()->count();

    $result = BuildRegenerationPreviewService::call(invoice: $f['invoice']);

    expect($result->success())->toBeTrue();

    $preview = $result->fee ?? null;
    $invoice = $result->invoice;

    expect($invoice->id)->toBe($f['invoice']->id)
        // Totals recomputed from the duplicated fees.
        ->and((int) $invoice->fees_amount_cents)->toBe(1000)
        ->and((int) $invoice->total_amount_cents)->toBe(1000)
        // Nothing persisted.
        ->and(Invoice::query()->count())->toBe($invoicesCount)
        ->and(App\Models\Fee::query()->count())->toBe($feesCount)
        ->and($f['invoice']->refresh()->fees_amount_cents)->toBe(0);
})->group('ledger:svc:Invoices.BuildRegenerationPreviewService');
