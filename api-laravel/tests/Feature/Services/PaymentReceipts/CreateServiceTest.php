<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Queue;
use App\Services\PaymentReceipts\CreateService;
use App\Jobs\PaymentReceipts\GenerateDocumentsJob;

/**
 * Ports of Rails' spec/services/payment_receipts/create_service_spec.rb over
 * the frozen schema. Ledger row: svc:payment_receipts:create.
 *
 * The receipt NUMBER comes from the frozen schema's
 * set_payment_receipt_number() trigger (before_payment_receipt_insert) —
 * Rails assigns it there too (app/models/payment_receipt.rb holds no number
 * logic), so the trigger test doubles as the number-semantics spec.
 */
beforeEach(function (): void {
    Queue::fake();
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

function receiptsOrganization(array $attributes = []): Organization
{
    // Rails: organization with the issue_receipts premium integration.
    return Organization::factory()->create(array_merge([
        'premium_integrations' => ['issue_receipts'],
    ], $attributes));
}

function receiptsPayment(Organization $organization): Payment
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')
        ->create(['total_amount_cents' => 1000]);

    return Payment::factory()->forInvoice($invoice)->create();
}

it('creates a payment receipt for a succeeded payment', function (): void {
    $organization = receiptsOrganization();
    $payment = receiptsPayment($organization);

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue();

    $receipt = $result->payment_receipt;

    expect($receipt)->toBeInstanceOf(PaymentReceipt::class)
        ->and($receipt->payment_id)->toBe($payment->id)
        ->and($receipt->organization_id)->toBe($organization->id)
        ->and($receipt->number)->toBeNull(); // assigned by the trigger on INSERT...

    // ...which this very INSERT already fired: the persisted row carries it.
    $persisted = $receipt->refresh();
    $slug = $payment->payable->customer->slug;

    expect($persisted->number)->toBe($slug.'-RCPT-000001');

    Queue::assertPushedOn('low_priority', GenerateDocumentsJob::class, fn (GenerateDocumentsJob $job): bool => $job->paymentReceipt->is($receipt) && $job->notify === false);
});

it('fires the number trigger with the zero-padded customer counter', function (): void {
    $organization = receiptsOrganization();

    // Both payments on one customer — the counter lives on the customer.
    $customer = Customer::factory()->forOrganization($organization)->create();
    $firstInvoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')->create();
    $payment = Payment::factory()->forInvoice($firstInvoice)->create();

    // Insert with number = NULL — the exact production shape.
    $receipt = PaymentReceipt::query()->create([
        'payment_id' => $payment->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $payment->payable->billing_entity_id,
        'number' => null,
    ])->refresh();

    $next = (int) $customer->refresh()->payment_receipt_counter;

    expect($receipt->number)->toBe($customer->slug.'-RCPT-'.mb_str_pad((string) $next, 6, '0', STR_PAD_LEFT))
        ->and($next)->toBe(1);

    // A second receipt (another payment) continues the counter.
    $secondInvoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')->create();
    $secondPayment = Payment::factory()->forInvoice($secondInvoice)->create();
    $second = PaymentReceipt::query()->create([
        'payment_id' => $secondPayment->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $secondPayment->payable->billing_entity_id,
        'number' => null,
    ])->refresh();

    expect($second->number)->toBe($customer->slug.'-RCPT-000002');
});

it('is forbidden without the issue_receipts entitlement', function (): void {
    $organization = Organization::factory()->create(); // no premium integrations
    $payment = receiptsPayment($organization);

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeFalse()
        ->and(PaymentReceipt::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('is forbidden without a premium license', function (): void {
    config(['lago.license' => null]);

    $organization = receiptsOrganization();
    $payment = receiptsPayment($organization);

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeFalse();
});

it('skips partner account payments', function (): void {
    $organization = receiptsOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create(['account_type' => 'partner']);
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')->create();
    $payment = Payment::factory()->forInvoice($invoice)->create();

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->payment_receipt)->toBeNull()
        ->and(PaymentReceipt::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('skips payments that are not succeeded', function (): void {
    $organization = receiptsOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')->create();
    $payment = Payment::factory()->forInvoice($invoice)->pending()->create();

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->payment_receipt)->toBeNull()
        ->and(PaymentReceipt::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('returns the existing receipt on re-entry (idempotent)', function (): void {
    $organization = receiptsOrganization();
    $payment = receiptsPayment($organization);

    $first = CreateService::call(payment: $payment)->payment_receipt;
    $second = CreateService::call(payment: $payment)->payment_receipt;

    expect($second->id)->toBe($first->id);
});

it('notifies through the documents job when the entity subscribes to the email', function (): void {
    $organization = receiptsOrganization();
    $payment = receiptsPayment($organization);

    // Rails: License.premium? && billing_entity.email_settings
    //   .include?("payment_receipt.created").
    $payment->payable->billingEntity->update([
        'email_settings' => ['invoice.finalized', 'credit_note.created', 'payment_receipt.created'],
    ]);

    $result = CreateService::call(payment: $payment);

    Queue::assertPushed(GenerateDocumentsJob::class, fn (GenerateDocumentsJob $job): bool => $job->notify === true);
    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_receipt.created');
});

it('answers not_found without a payment', function (): void {
    $result = CreateService::call(payment: null);

    expect($result->success())->toBeFalse();
});
