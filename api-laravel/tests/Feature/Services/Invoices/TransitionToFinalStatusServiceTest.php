<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Services\Invoices\TransitionToFinalStatusService;

/**
 * Port of spec/services/invoices/transition_to_final_status_service_spec.rb
 * — the finalize-vs-closed decision from the zero-amount invoice settings.
 *
 * NOT ported from the Rails spec: the `subscription_gated` contexts
 * (payment / tax-pending invoices stay open) — activation rules are not
 * ported yet, Subscription#gated? is always false in M1, so the gated
 * branch is unreachable (TODO(port) on Subscription::pendingRules).
 */
function transitionFixture(App\Enums\FinalizeZeroAmountInvoice $customerSetting, bool $billingEntitySetting): array
{
    $organization = App\Models\Organization::factory()->create();
    $billingEntity = App\Models\BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'finalize_zero_amount_invoice' => $billingEntitySetting,
    ]);
    $customer = App\Models\Customer::factory()->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
        'finalize_zero_amount_invoice' => $customerSetting,
    ]);

    return compact('organization', 'billingEntity', 'customer');
}

function transitionInvoice(array $f, int $feesAmountCents): Invoice
{
    return Invoice::factory()->create([
        'organization_id' => $f['organization']->id,
        'customer_id' => $f['customer']->id,
        'billing_entity_id' => $f['billingEntity']->id,
        'currency' => 'EUR',
        'fees_amount_cents' => $feesAmountCents,
        'issuing_date' => now('UTC')->startOfMonth()->toDateString(),
    ]);
}

it('finalizes an invoice with non-zero fees regardless of the zero settings', function (): void {
    // Customer "skip" + billing entity false must not matter — fees are non-zero.
    $f = transitionFixture(App\Enums\FinalizeZeroAmountInvoice::Skip, false);
    $invoice = transitionInvoice($f, 100);

    TransitionToFinalStatusService::call(invoice: $invoice);

    // Rails asserts invoice.status in memory — the Closed branch is an
    // unsaved assignment; the caller (SubscriptionService) persists it.
    expect($invoice->status)->toBe(InvoiceStatus::Finalized);
})->group('ledger:svc:Invoices.TransitionToFinalStatusService');

it('finalizes a zero-amount invoice when the customer setting is finalize', function (): void {
    $f = transitionFixture(App\Enums\FinalizeZeroAmountInvoice::Finalize, false);
    $invoice = transitionInvoice($f, 0);

    TransitionToFinalStatusService::call(invoice: $invoice);

    expect($invoice->status)->toBe(InvoiceStatus::Finalized);
})->group('ledger:svc:Invoices.TransitionToFinalStatusService');

it('closes a zero-amount invoice when the customer setting is skip', function (): void {
    $f = transitionFixture(App\Enums\FinalizeZeroAmountInvoice::Skip, true);
    $invoice = transitionInvoice($f, 0);

    TransitionToFinalStatusService::call(invoice: $invoice);

    expect($invoice->status)->toBe(InvoiceStatus::Closed);
})->group('ledger:svc:Invoices.TransitionToFinalStatusService');

it('inherits the billing entity finalize setting when the customer inherits', function (bool $billingEntitySetting, InvoiceStatus $expected): void {
    $f = transitionFixture(App\Enums\FinalizeZeroAmountInvoice::Inherit, $billingEntitySetting);
    $invoice = transitionInvoice($f, 0);

    TransitionToFinalStatusService::call(invoice: $invoice);

    expect($invoice->status)->toBe($expected);
})->with([
    'billing entity finalizes' => [true, InvoiceStatus::Finalized],
    'billing entity skips' => [false, InvoiceStatus::Closed],
])->group('ledger:svc:Invoices.TransitionToFinalStatusService');
