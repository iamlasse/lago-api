<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/payment_receipts',
    'ledger:rest:GET:/api/v1/payment_receipts/:id',
    'ledger:rest:POST:/api/v1/payment_receipts/:id/resend_email',
);

use App\Models\Payment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/requests/api/v1/payment_receipts_controller_spec.rb —
 * index (invoice_id filter), show and resend_email. Ledger rows above; the
 * v2 mirrors reuse the same controller (ledger rows
 * GET:/api/v2/payment_receipts[...]).
 */
beforeEach(function (): void {
    Queue::fake();
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

function receiptsApiOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create(array_merge([
        'premium_integrations' => ['issue_receipts'],
    ], $attributes));

    return [$organization, $organization->apiKeys()->first()];
}

function receiptsApiReceipt(Organization $organization, array $paymentAttributes = []): PaymentReceipt
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')->create();

    $payment = Payment::factory()->forInvoice($invoice)->create($paymentAttributes);

    // number = NULL — the frozen set_payment_receipt_number() trigger fills it.
    return PaymentReceipt::query()->create([
        'payment_id' => $payment->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $invoice->billing_entity_id,
        'number' => null,
    ])->refresh();
}

it('lists payment receipts', function (): void {
    [$organization, $apiKey] = receiptsApiOrganization();
    $receipt = receiptsApiReceipt($organization);

    $response = $this->getJson('/api/v1/payment_receipts', ['Authorization' => 'Bearer '.$apiKey->value]);

    $response->assertOk();

    $payload = $response->json('payment_receipts');

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['lago_id'])->toBe($receipt->id)
        ->and($payload[0]['number'])->toBe($receipt->number)
        ->and($payload[0]['payment']['lago_id'])->toBe($receipt->payment_id)
        ->and($response->json('meta.total_count'))->toBe(1);
});

it('filters payment receipts by invoice', function (): void {
    [$organization, $apiKey] = receiptsApiOrganization();
    $receipt = receiptsApiReceipt($organization);
    $other = receiptsApiReceipt($organization);

    $invoiceId = $receipt->payment->payable_id;

    $response = $this->getJson("/api/v1/payment_receipts?invoice_id={$invoiceId}", ['Authorization' => 'Bearer '.$apiKey->value]);

    $response->assertOk();

    $ids = collect($response->json('payment_receipts'))->pluck('lago_id');

    expect($ids->all())->toBe([$receipt->id]);
});

it('shows a payment receipt', function (): void {
    [$organization, $apiKey] = receiptsApiOrganization();
    $receipt = receiptsApiReceipt($organization);

    $response = $this->getJson("/api/v1/payment_receipts/{$receipt->id}", ['Authorization' => 'Bearer '.$apiKey->value]);

    $response->assertOk();

    expect($response->json('payment_receipt.lago_id'))->toBe($receipt->id)
        ->and($response->json('payment_receipt.number'))->toBe($receipt->number)
        ->and($response->json('payment_receipt.file_url'))->toBeNull()
        ->and($response->json('payment_receipt.xml_url'))->toBeNull();
});

it('answers not_found for an unknown receipt', function (): void {
    [$organization, $apiKey] = receiptsApiOrganization();

    $this->getJson('/api/v1/payment_receipts/00000000-0000-0000-0000-000000000000', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('answers not_found for another organization receipt', function (): void {
    [$organization, $apiKey] = receiptsApiOrganization();
    $foreign = receiptsApiReceipt(Organization::factory()->create());

    $this->getJson("/api/v1/payment_receipts/{$foreign->id}", ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('answers ok on resend_email', function (): void {
    [$organization, $apiKey] = receiptsApiOrganization();
    $receipt = receiptsApiReceipt($organization);
    $receipt->billingEntity->update(['email_settings' => ['payment_receipt.created']]);
    // Rails: billing_entity.from_email_address (LAGO_FROM_EMAIL).
    config(['lago.from_email' => 'sender@acme.com']);

    $response = $this->postJson(
        "/api/v1/payment_receipts/{$receipt->id}/resend_email",
        ['to' => ['owner@acme.com']],
        ['Authorization' => 'Bearer '.$apiKey->value],
    );

    $response->assertOk();
});

it('answers forbidden on resend_email without a premium license', function (): void {
    config(['lago.license' => null]);
    [$organization, $apiKey] = receiptsApiOrganization();
    $receipt = receiptsApiReceipt($organization);

    $this->postJson(
        "/api/v1/payment_receipts/{$receipt->id}/resend_email",
        [],
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertForbidden();
});

it('answers not_found on resend_email for an unknown receipt', function (): void {
    [$organization, $apiKey] = receiptsApiOrganization();

    $this->postJson(
        '/api/v1/payment_receipts/00000000-0000-0000-0000-000000000000/resend_email',
        [],
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertNotFound();
});
