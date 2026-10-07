<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/payments',
    'ledger:rest:GET:/api/v1/payments',
    'ledger:rest:GET:/api/v1/payments/:id',
    'ledger:rest:GET:/api/v1/customers/:external_id/payments',
);

use App\Models\Payment;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/requests/api/v1/payments_controller_spec.rb — manual
 * payment recording (premium), index with filters, show, and the
 * customer-nested index.
 */
function paymentsOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function paymentsCustomerWithInvoice(Organization $organization): array
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')
        ->create(['total_amount_cents' => 1000]);

    return [$customer, $invoice];
}

beforeEach(function (): void {
    Queue::fake();
});

afterEach(function (): void {
    // putenv leaks across tests in the same process — restore the default
    // (no license) so premium-gated behavior elsewhere is unaffected.
    config(['lago.license' => null]);
});

// -- POST /api/v1/payments -------------------------------------------------------

it('rejects a manual payment without premium license', function (): void {
    config(['lago.license' => null]);
    [$organization, $apiKey] = paymentsOrganization();
    [, $invoice] = paymentsCustomerWithInvoice($organization);

    $this->postJson('/api/v1/payments', ['payment' => [
        'invoice_id' => $invoice->id,
        'amount_cents' => 1000,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden();
});

it('rejects a manual payment with a missing amount', function (): void {
    config(['lago.license' => 'premium-license-token']);
    [$organization, $apiKey] = paymentsOrganization();
    [, $invoice] = paymentsCustomerWithInvoice($organization);

    $this->postJson('/api/v1/payments', ['payment' => [
        'invoice_id' => $invoice->id,
        'amount_in_cents' => 1000,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.amount_cents', ['invalid_value']);
});

it('records a manual payment and settles the invoice', function (): void {
    config(['lago.license' => 'premium-license-token']);
    [$organization, $apiKey] = paymentsOrganization();
    [$customer, $invoice] = paymentsCustomerWithInvoice($organization);

    $this->postJson('/api/v1/payments', ['payment' => [
        'invoice_id' => $invoice->id,
        'amount_cents' => 1000,
        'reference' => 'Bank transfer #12',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($invoice, $customer): void {
            $json->where('payment.amount_cents', 1000)
                ->where('payment.amount_currency', 'EUR')
                ->where('payment.status', 'succeeded')
                ->where('payment.payment_status', 'succeeded')
                ->where('payment.type', 'manual')
                ->where('payment.reference', 'Bank transfer #12')
                ->where('payment.payable_type', 'Invoice')
                ->where('payment.lago_payable_id', $invoice->id)
                ->where('payment.invoice_ids', [$invoice->id])
                ->where('payment.external_customer_id', $customer->external_id)
                ->etc();
        });

    expect($invoice->refresh()->paymentStatusEnum()->label())->toBe('succeeded')
        ->and($invoice->total_paid_amount_cents)->toBe(1000);
});

// -- GET /api/v1/payments ---------------------------------------------------------

it('lists the organization payments', function (): void {
    [$organization, $apiKey] = paymentsOrganization();
    [$customer, $invoice] = paymentsCustomerWithInvoice($organization);

    $payment = Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create();

    $this->getJson('/api/v1/payments', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($payment): void {
            $json->count('payments', 1)
                ->where('payments.0.lago_id', $payment->id)
                ->where('payments.0.payment_status', 'succeeded')
                ->where('meta.total_count', 1)
                ->etc();
        });
});

it('filters payments by external_customer_id', function (): void {
    [$organization, $apiKey] = paymentsOrganization();
    [$customer, $invoice] = paymentsCustomerWithInvoice($organization);
    $otherCustomer = Customer::factory()->forOrganization($organization)->create();

    Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create();

    $this->getJson('/api/v1/payments?external_customer_id='.$otherCustomer->external_id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('payments', 0)
                ->where('meta.total_count', 0)
                ->etc();
        });
});

// -- GET /api/v1/payments/:id ------------------------------------------------------

it('shows a payment', function (): void {
    [$organization, $apiKey] = paymentsOrganization();
    [$customer, $invoice] = paymentsCustomerWithInvoice($organization);
    $payment = Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create();

    $this->getJson('/api/v1/payments/'.$payment->id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('payment.lago_id', $payment->id);
});

it('answers not_found for an unknown payment', function (): void {
    [$organization, $apiKey] = paymentsOrganization();

    $this->getJson('/api/v1/payments/00000000-0000-0000-0000-000000000000', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'payment_not_found');
});

// -- GET /api/v1/customers/:external_id/payments ------------------------------------

it('lists the payments of a customer', function (): void {
    [$organization, $apiKey] = paymentsOrganization();
    [$customer, $invoice] = paymentsCustomerWithInvoice($organization);
    Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create();

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/payments', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('payments', 1)
                ->where('meta.total_count', 1)
                ->etc();
        });
});

it('answers not_found for an unknown customer payments index', function (): void {
    [$organization, $apiKey] = paymentsOrganization();

    $this->getJson('/api/v1/customers/unknown/payments', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- API key permissions ------------------------------------------------------------

it('enforces the payment write permission on create', function (): void {
    config(['lago.license' => 'premium-license-token']);
    [$organization, $apiKey] = paymentsOrganization();
    [$customer, $invoice] = paymentsCustomerWithInvoice($organization);

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['payment' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/payments', ['payment' => [
        'invoice_id' => $invoice->id,
        'amount_cents' => 1000,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertJsonPath('code', 'write_action_not_allowed_for_payment');
});
