<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Payment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Queue;

/**
 * Ledger rows for the receipt GraphQL surface: the Payment.paymentReceipt
 * field of the frozen SDL plus the downloadPaymentReceipt /
 * downloadXmlPaymentReceipt / resendPaymentReceiptEmail mutations.
 */
beforeEach(function (): void {
    Queue::fake();
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

function receiptGqlOrganization(): array
{
    $organization = Organization::factory()->create([
        'premium_integrations' => ['issue_receipts'],
    ]);

    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function receiptGqlReceipt(Organization $organization): PaymentReceipt
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')->create();
    $payment = Payment::factory()->forInvoice($invoice)->create();

    return PaymentReceipt::query()->create([
        'payment_id' => $payment->id,
        'organization_id' => $organization->id,
        'billing_entity_id' => $invoice->billing_entity_id,
        'number' => null, // the frozen set_payment_receipt_number() trigger.
    ])->refresh();
}

const PAYMENT_RECEIPT_FIELD_QUERY = <<<'GQL'
query($id: ID!) {
    payment(id: $id) {
        id
        paymentReceipt { id number fileUrl xmlUrl }
    }
}
GQL;

const RESEND_RECEIPT_MUTATION = <<<'GQL'
mutation($input: ResendPaymentReceiptEmailInput!) {
    resendPaymentReceiptEmail(input: $input) { id }
}
GQL;

const DOWNLOAD_RECEIPT_MUTATION = <<<'GQL'
mutation($input: DownloadPaymentReceiptInput!) {
    downloadPaymentReceipt(input: $input) { id }
}
GQL;

it('resolves paymentReceipt on the Payment type with the trigger-assigned number', function (): void {
    [$organization, $user] = receiptGqlOrganization();
    $receipt = receiptGqlReceipt($organization);

    $response = gqlPost(PAYMENT_RECEIPT_FIELD_QUERY, ['id' => $receipt->payment_id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.payment.paymentReceipt');

    expect($payload['id'])->toBe($receipt->id)
        ->and($payload['number'])->toBe($receipt->number)
        ->and($payload['fileUrl'])->toBeNull()
        ->and($payload['xmlUrl'])->toBeNull();
});

it('answers not_found when downloading an unknown receipt', function (): void {
    [$organization, $user] = receiptGqlOrganization();

    $response = gqlPost(DOWNLOAD_RECEIPT_MUTATION, ['input' => [
        'id' => '00000000-0000-0000-0000-000000000000',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.status'))->toBe(404)
        ->and($response->json('errors.0.extensions.code'))->toBe('not_found');
});

it('resends the receipt email and answers the receipt', function (): void {
    [$organization, $user] = receiptGqlOrganization();
    $receipt = receiptGqlReceipt($organization);
    config(['lago.from_email' => 'sender@acme.com']);

    $response = gqlPost(RESEND_RECEIPT_MUTATION, ['input' => [
        'id' => $receipt->id,
        'to' => ['owner@acme.com'],
    ]], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('data.resendPaymentReceiptEmail.id'))->toBe($receipt->id);
});

it('answers validation errors on resend without recipients', function (): void {
    [$organization, $user] = receiptGqlOrganization();
    $receipt = receiptGqlReceipt($organization);
    config(['lago.from_email' => 'sender@acme.com']);

    // A customer without an email → Rails' "must have at least one
    // recipient" validation error.
    $receipt->payment->payable->customer->update(['email' => null]);

    $response = gqlPost(RESEND_RECEIPT_MUTATION, ['input' => ['id' => $receipt->id]], gqlAuthHeaders($user, $organization->id));

    $extensions = $response->json('errors.0.extensions');

    expect($extensions['status'])->toBe(422);
});
