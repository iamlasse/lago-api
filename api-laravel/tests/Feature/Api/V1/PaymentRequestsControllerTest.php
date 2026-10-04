<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/payment_requests',
    'ledger:rest:GET:/api/v1/payment_requests',
    'ledger:rest:GET:/api/v1/payment_requests/:id',
    'ledger:rest:GET:/api/v1/customers/:external_id/payment_requests',
);

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/requests/api/v1/payment_requests_controller_spec.rb —
 * premium dunning payment requests over overdue invoices, index filters,
 * show, and the customer-nested index.
 */
function paymentRequestsOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function paymentRequestsCustomerWithOverdueInvoices(Organization $organization, int $count = 1): array
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoices = [];

    for ($i = 0; $i < $count; $i++) {
        $invoices[] = App\Models\Invoice::factory()->for($customer, 'customer')
            ->for($organization, 'organization')
            ->create(['total_amount_cents' => 500, 'payment_overdue' => true, 'ready_for_payment_processing' => true]);
    }

    return [$customer, $invoices];
}

beforeEach(function (): void {
    Queue::fake();
});

afterEach(function (): void {
    // putenv leaks across tests in the same process — restore the default
    // (no license) so premium-gated behavior elsewhere is unaffected.
    config(['lago.license' => null]);
});

// -- POST /api/v1/payment_requests -------------------------------------------------

it('rejects a payment request without premium license', function (): void {
    config(['lago.license' => null]);
    [$organization, $apiKey] = paymentRequestsOrganization();
    [$customer, $invoices] = paymentRequestsCustomerWithOverdueInvoices($organization);

    $this->postJson('/api/v1/payment_requests', ['payment_request' => [
        'external_customer_id' => $customer->external_id,
        'lago_invoice_ids' => [$invoices[0]->id],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden();
});

it('rejects a payment request when invoices are not overdue', function (): void {
    config(['lago.license' => 'premium-license-token']);
    [$organization, $apiKey] = paymentRequestsOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $notOverdue = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')
        ->create(['total_amount_cents' => 500, 'payment_overdue' => false]);

    $this->postJson('/api/v1/payment_requests', ['payment_request' => [
        'external_customer_id' => $customer->external_id,
        'lago_invoice_ids' => [$notOverdue->id],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405)
        ->assertJsonPath('code', 'invoices_not_overdue');
});

it('creates a payment request over overdue invoices', function (): void {
    config(['lago.license' => 'premium-license-token']);
    [$organization, $apiKey] = paymentRequestsOrganization();
    [$customer, $invoices] = paymentRequestsCustomerWithOverdueInvoices($organization, 2);

    $this->postJson('/api/v1/payment_requests', ['payment_request' => [
        'external_customer_id' => $customer->external_id,
        'lago_invoice_ids' => [$invoices[0]->id, $invoices[1]->id],
        'email' => 'billing@example.com',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer) {
            $json->where('payment_request.amount_cents', 1000)
                ->where('payment_request.amount_currency', 'EUR')
                ->where('payment_request.email', 'billing@example.com')
                ->where('payment_request.payment_status', 'pending')
                ->where('payment_request.customer.customer.lago_id', $customer->id)
                ->count('payment_request.invoices', 2)
                ->etc();
        });

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job): bool => $job->webhookType === 'payment_request.created');
});

// -- GET /api/v1/payment_requests ----------------------------------------------------

it('lists the organization payment requests', function (): void {
    [$organization, $apiKey] = paymentRequestsOrganization();
    [$customer, $invoices] = paymentRequestsCustomerWithOverdueInvoices($organization);
    App\Models\PaymentRequest::factory()->forCustomer($customer)->create();

    $this->getJson('/api/v1/payment_requests', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('payment_requests', 1)
                ->where('meta.total_count', 1)
                ->etc();
        });
});

it('filters payment requests by payment_status', function (): void {
    [$organization, $apiKey] = paymentRequestsOrganization();
    [$customer, $invoices] = paymentRequestsCustomerWithOverdueInvoices($organization);
    App\Models\PaymentRequest::factory()->forCustomer($customer)->create();
    App\Models\PaymentRequest::factory()->forCustomer($customer)->succeeded()->create();

    $this->getJson('/api/v1/payment_requests?payment_status=succeeded', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('payment_requests', 1)
                ->where('payment_requests.0.payment_status', 'succeeded')
                ->etc();
        });
});

// -- GET /api/v1/payment_requests/:id -------------------------------------------------

it('shows a payment request', function (): void {
    [$organization, $apiKey] = paymentRequestsOrganization();
    [$customer, $invoices] = paymentRequestsCustomerWithOverdueInvoices($organization);
    $request = App\Models\PaymentRequest::factory()->forCustomer($customer)->create();

    $this->getJson('/api/v1/payment_requests/'.$request->id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('payment_request.lago_id', $request->id)
        ->assertJsonPath('payment_request.customer.customer.lago_id', $customer->id);
});

it('answers not_found for an unknown payment request', function (): void {
    [$organization, $apiKey] = paymentRequestsOrganization();

    $this->getJson('/api/v1/payment_requests/00000000-0000-0000-0000-000000000000', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'payment_request_not_found');
});

// -- GET /api/v1/customers/:external_id/payment_requests -------------------------------

it('lists the payment requests of a customer', function (): void {
    [$organization, $apiKey] = paymentRequestsOrganization();
    [$customer, $invoices] = paymentRequestsCustomerWithOverdueInvoices($organization);
    App\Models\PaymentRequest::factory()->forCustomer($customer)->create();

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/payment_requests', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('payment_requests', 1)
                ->etc();
        });
});
