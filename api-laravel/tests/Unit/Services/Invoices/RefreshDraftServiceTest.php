<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Tax;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use App\Models\InvoiceSubscription;
use App\Services\Failures\ForbiddenFailure;
use App\Services\Invoices\RefreshDraftService;

uses()->group('ledger:svc:Invoices.RefreshDraftService');

/**
 * Port of Rails' spec/services/invoices/refresh_draft_service_spec.rb — the
 * credit-note refresh and lifetime-usage flags are TODO(port).
 */
function refreshScenario(array $invoiceAttributes = []): array
{
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->draft()->create([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'invoice_type' => InvoiceType::Subscription,
        ...$invoiceAttributes,
    ]);

    $subscription = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
    ]);

    $invoiceSubscription = InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'recurring' => true,
    ]);

    return [$invoice, $subscription, $invoiceSubscription];
}

function refreshEntityTax(object $billingEntity, object $organization, float $rate = 15): Tax
{
    $tax = Tax::factory()->create(['organization_id' => $organization->id, 'rate' => $rate]);

    DB::table('billing_entities_taxes')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'billing_entity_id' => $billingEntity->id,
        'tax_id' => $tax->id,
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tax;
}

it('returns a forbidden failure when the invoice is not a subscription invoice', function (InvoiceType $invoiceType): void {
    $invoice = Invoice::factory()->draft()->create(['invoice_type' => $invoiceType]);

    $result = new RefreshDraftService(invoice: $invoice)->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(ForbiddenFailure::class);
})->with([
    InvoiceType::OneOff,
    InvoiceType::AddOn,
    InvoiceType::Credit,
    InvoiceType::AdvanceCharges,
    InvoiceType::ProgressiveBilling,
]);

it('updates ready_to_be_refreshed to false', function (): void {
    [$invoice] = refreshScenario(['ready_to_be_refreshed' => true]);

    $result = new RefreshDraftService(invoice: $invoice)->execute();

    expect($result->success())->toBeTrue();
    expect($invoice->refresh()->ready_to_be_refreshed)->toBeFalse();
});

it('does not refresh a finalized invoice', function (): void {
    [$invoice] = refreshScenario(['status' => InvoiceStatus::Finalized]);

    $result = new RefreshDraftService(invoice: $invoice)->execute();

    expect($result->success())->toBeTrue();
    // Rails: CalculateFeesService is not called — the updated_at is untouched.
    expect($invoice->refresh()->isFinalized())->toBeTrue();
});

it('regenerates the fees and stamps the invoice created_at on them', function (): void {
    [$invoice, $subscription] = refreshScenario([
        'taxes_amount_cents' => 10,
        'total_amount_cents' => 1000110010,
        'taxes_rate' => 30,
        'fees_amount_cents' => 2600,
        'sub_total_excluding_taxes_amount_cents' => 9900090,
        'sub_total_including_taxes_amount_cents' => 9900100,
        'progressive_billing_credit_amount_cents' => 1239000,
    ]);

    refreshEntityTax($invoice->billingEntity, $invoice->organization);

    $oldFee = Fee::factory()->create(['invoice_id' => $invoice->id]);

    $result = new RefreshDraftService(invoice: $invoice)->execute();

    expect($result->success())->toBeTrue();

    $invoice->refresh();

    // The old fee is gone (destroy_all + regeneration).
    expect(Fee::query()->whereKey($oldFee->id)->exists())->toBeFalse();
    expect($invoice->fees()->count())->toBeGreaterThan(0);

    // NOTE: fees.update_all(created_at: invoice.created_at).
    expect($invoice->fees()->pluck('created_at')->unique()->all())
        ->toEqual([$invoice->created_at->toDateTimeString()]);

    // Totals reset then recomputed.
    expect($invoice->progressive_billing_credit_amount_cents)->toBe(0);
    expect($invoice->taxes_rate)->toEqual(15.0);
});

it('recalculates progressive billing amount to zero', function (): void {
    [$invoice] = refreshScenario([
        'progressive_billing_credit_amount_cents' => 1239000,
    ]);

    $result = new RefreshDraftService(invoice: $invoice)->execute();

    expect($result->success())->toBeTrue();
    expect($invoice->refresh()->progressive_billing_credit_amount_cents)->toBe(0);
});

it('recreates the invoice subscriptions with the recurring flag kept', function (): void {
    [$invoice] = refreshScenario();

    $result = new RefreshDraftService(invoice: $invoice)->execute();

    expect($result->success())->toBeTrue();
    expect($invoice->refresh()->invoiceSubscriptions()->count())->toBe(1);
    expect($invoice->refresh()->invoiceSubscriptions()->first()->recurring)->toBeTrue();
});
