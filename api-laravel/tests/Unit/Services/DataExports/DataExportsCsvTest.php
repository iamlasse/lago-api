<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Invoice;
use App\Models\CreditNote;
use App\Models\DataExport;
use App\Models\CreditNoteItem;
use App\Models\DataExportPart;
use App\Models\InvoiceSubscription;
use Database\Factories\CreditNoteFactory;
use App\Services\DataExports\Csv\Invoices;
use App\Services\DataExports\Csv\CreditNotes;
use App\Services\DataExports\Csv\InvoiceFees;
use App\Services\DataExports\Csv\CreditNoteItems;
use App\Services\DataExports\Csv\ResolveFeeBillingPeriodService;

uses()->group('ledger:svc:DataExports.Csv');

/**
 * Ports of Rails' spec/services/data_exports/csv/{invoices, credit_notes,
 * credit_note_items, invoice_fees, resolve_fee_billing_period_service}_spec.rb.
 */
function csvOrg(): object
{
    return App\Models\Organization::factory()->create();
}

function csvPart(object $organization, array $objectIds, string $resourceType = 'invoices'): DataExportPart
{
    $dataExport = DataExport::factory()
        ->forOrganization($organization)
        ->state(fn (): array => ['resource_type' => $resourceType])
        ->create();

    return DataExportPart::factory()->forDataExport($dataExport)->create([
        'object_ids' => $objectIds,
    ]);
}

it('exports invoice rows with the base headers', function (): void {
    $organization = csvOrg();
    $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);

    $part = csvPart($organization, [$invoice->id]);

    $csv = Invoices::call(dataExportPart: $part)->raiseIfError()->csv;

    $lines = explode("\n", mb_trim($csv));

    expect(count($lines))->toBe(1)
        ->and(str_starts_with($lines[0], $invoice->id.','))->toBeTrue()
        ->and(str_contains($lines[0], 'subscription,pending,finalized,'))->toBeTrue()
        ->and(Invoices::headers($part))->toBe(Invoices::BASE_HEADERS);
});

it('exports credit note rows', function (): void {
    $organization = csvOrg();
    /** @var CreditNoteFactory $factory */
    $factory = CreditNote::factory();
    $creditNote = $factory->create(['organization_id' => $organization->id]);

    $part = csvPart($organization, [$creditNote->id], 'credit_notes');

    $csv = CreditNotes::call(dataExportPart: $part)->raiseIfError()->csv;

    $lines = explode("\n", mb_trim($csv));

    expect(count($lines))->toBe(1)
        ->and(str_starts_with($lines[0], $creditNote->id.','))->toBeTrue()
        ->and(str_contains($lines[0], 'duplicated_charge,'))->toBeTrue()
        ->and(CreditNotes::headers($part))->toBe(CreditNotes::BASE_HEADERS);
});

it('exports credit note item rows', function (): void {
    $organization = csvOrg();
    /** @var CreditNoteFactory $factory */
    $factory = CreditNote::factory();
    $creditNote = $factory->create(['organization_id' => $organization->id]);

    $fee = Fee::factory()->create([
        'invoice_id' => $creditNote->invoice_id,
        'fee_type' => FeeType::Charge->value,
        'amount_cents' => 420,
        'amount_currency' => 'EUR',
    ]);

    $item = CreditNoteItem::query()->create([
        'organization_id' => $organization->id,
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'amount_cents' => 420,
        'precise_amount_cents' => '420',
        'amount_currency' => 'EUR',
    ]);

    $part = csvPart($organization, [$creditNote->id], 'credit_note_items');

    $csv = CreditNoteItems::call(dataExportPart: $part)->raiseIfError()->csv;

    $lines = explode("\n", mb_trim($csv));

    expect(count($lines))->toBe(1)
        ->and(str_contains($lines[0], $creditNote->number))->toBeTrue()
        ->and(str_contains($lines[0], $item->id))->toBeTrue()
        ->and(str_contains($lines[0], $fee->id))->toBeTrue()
        ->and(str_contains($lines[0], 'EUR'))->toBeTrue();
});

it('resolves a subscription fee billing period from the fee properties', function (): void {
    $organization = csvOrg();
    $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);
    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'fee_type' => FeeType::Subscription->value,
        'properties' => [
            'from_datetime' => '2024-01-01T00:00:00Z',
            'to_datetime' => '2024-01-31T23:59:59Z',
        ],
    ]);

    $invoiceSubscription = InvoiceSubscription::factory()->create(['invoice_id' => $invoice->id]);

    $result = ResolveFeeBillingPeriodService::call(fee: $fee, invoiceSubscription: $invoiceSubscription)->raiseIfError();

    expect($result->from_datetime->toIso8601String())->toStartWith('2024-01-01T00:00:00')
        ->and($result->to_datetime->toIso8601String())->toStartWith('2024-01-31T23:59:59');
});

it('falls back to the invoice subscription period when the fee has no properties', function (): void {
    $organization = csvOrg();
    $invoice = Invoice::factory()->create(['organization_id' => $organization->id]);
    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'fee_type' => FeeType::Subscription->value,
        'properties' => [],
    ]);

    $invoiceSubscription = InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'from_datetime' => '2024-02-01 00:00:00',
        'to_datetime' => '2024-02-29 23:59:59',
    ]);

    $result = ResolveFeeBillingPeriodService::call(fee: $fee, invoiceSubscription: $invoiceSubscription)->raiseIfError();

    expect($result->from_datetime->toDateString())->toBe('2024-02-01')
        ->and($result->to_datetime->toDateString())->toBe('2024-02-29');
});

it('exports invoice fee rows with the resolved period dates', function (): void {
    $organization = csvOrg();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $subscription = App\Models\Subscription::factory()->forCustomer($customer)->create();

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'fee_type' => FeeType::Subscription->value,
        'properties' => [
            'from_datetime' => '2024-01-01T00:00:00Z',
            'to_datetime' => '2024-01-31T23:59:59Z',
        ],
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
    ]);

    InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
    ]);

    $part = csvPart($organization, [$invoice->id], 'invoice_fees');

    $csv = InvoiceFees::call(dataExportPart: $part)->raiseIfError()->csv;

    $lines = explode("\n", mb_trim($csv));

    expect(str_starts_with($lines[0], $invoice->id.','))->toBeTrue()
        ->and(str_contains($lines[0], $fee->id))->toBeTrue()
        // The billing period dates, rendered in the customer's timezone (UTC).
        ->and(str_contains($lines[0], ',2024-01-01,2024-01-31,'))->toBeTrue()
        ->and(InvoiceFees::headers($part))->toBe(InvoiceFees::BASE_HEADERS);
});
