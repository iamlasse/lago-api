<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use App\Models\InvoiceSubscription;
use App\Services\Failures\NotFoundFailure;
use App\Services\Invoices\FinalizeService;
use App\Services\Failures\ValidationFailure;

/**
 * Port of spec/services/invoices/finalize_service_spec.rb — the scenarios
 * beyond the happy path covered by the pipeline test in
 * CalculateFeesServiceTest.php: the already-finalized no-op, the missing
 * invoice, a failing save, and the purchase-order-number retention.
 */
function finalizeFixture(array $invoiceOverrides = []): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();

    $invoice = Invoice::factory()->draft()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'currency' => 'EUR',
    ], $invoiceOverrides));

    return compact('organization', 'customer', 'invoice');
}

it('finalizes an already finalized invoice without touching it', function (): void {
    $f = finalizeFixture();

    // Second precision: the connection drops sub-second precision on write.
    $finalizedAt = now()->subDays(2)->startOfSecond();
    DB::table('invoices')->where('id', $f['invoice']->id)->update([
        'status' => InvoiceStatus::Finalized->value,
        'finalized_at' => $finalizedAt,
    ]);

    $invoice = $f['invoice']->fresh();

    $result = FinalizeService::call(invoice: $invoice);

    expect($result->success())->toBeTrue()
        ->and($result->invoice->isFinalized())->toBeTrue()
        ->and($result->invoice->fresh()->finalized_at->equalTo($finalizedAt))->toBeTrue();
})->group('ledger:svc:Invoices.FinalizeService');

it('returns a not found failure when the invoice is missing', function (): void {
    $result = FinalizeService::call(invoice: null);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('invoice');
})->group('ledger:svc:Invoices.FinalizeService');

it('keeps the invoice purchase order number when the subscription has a different one', function (): void {
    $f = finalizeFixture(['purchase_order_number' => 'PO-ORIGINAL']);

    $plan = App\Models\Plan::factory()->create([
        'organization_id' => $f['organization']->id,
    ]);
    $subscription = Subscription::factory()->create([
        'customer_id' => $f['customer']->id,
        'plan_id' => $plan->id,
        'organization_id' => $f['organization']->id,
        'purchase_order_number' => 'PO-UPDATED',
    ]);

    InvoiceSubscription::query()->create([
        'invoice_id' => $f['invoice']->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $f['organization']->id,
        'recurring' => true,
        'timestamp' => now(),
        'from_datetime' => now()->startOfMonth(),
        'to_datetime' => now()->startOfMonth()->addMonth(),
        'charges_from_datetime' => now()->startOfMonth(),
        'charges_to_datetime' => now()->startOfMonth()->addMonth(),
        'invoicing_reason' => 'subscription_periodic',
    ]);

    $result = FinalizeService::call(invoice: $f['invoice']);

    expect($result->success())->toBeTrue()
        ->and($result->invoice->refresh()->purchase_order_number)->toBe('PO-ORIGINAL');
})->group('ledger:svc:Invoices.FinalizeService');

it('returns a validation failure when the save fails', function (): void {
    $f = finalizeFixture();

    // Rails stubs `save!` to raise ActiveRecord::RecordInvalid; pointing
    // payment_method_id at a missing row makes the finalizing UPDATE fail
    // for real on the invoices → payment_methods foreign key — the Laravel
    // shape of the same rescue.
    $f['invoice']->payment_method_id = '00000000-0000-0000-0000-000000000001';

    $result = FinalizeService::call(invoice: $f['invoice']);

    expect($result->success())->toBeFalse()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class);
})->group('ledger:svc:Invoices.FinalizeService');
