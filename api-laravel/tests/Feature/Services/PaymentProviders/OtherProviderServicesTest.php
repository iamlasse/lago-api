<?php

declare(strict_types=1);

uses()->group('ledger:payment-providers');

use App\Jobs\PaymentProviders\AdyenCheckoutUrlJob;
use App\Jobs\PaymentProviders\AdyenCreateCustomerJob;
use App\Jobs\PaymentProviders\GocardlessCreateCustomerJob;
use App\Jobs\PaymentProviders\MoneyhashCreateCustomerJob;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\PaymentProvider;
use App\Services\Customers\PaymentBillingConfigurationService;
use App\Services\Invoices\Payments\GeneratePaymentUrlService;
use App\Services\PaymentProviders\CreatePaymentFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Port of the provider payment-leg and customer-leg specs — the
 * CreatePaymentFactory arms (Adyen / GoCardless / Cashfree / Moneyhash),
 * the provider customers create services (sync + checkout URL jobs), and
 * the POST /invoices/:id/payment_url flow (GeneratePaymentUrlService +
 * PaymentIntents::FetchService).
 */
function legsOrganization(string $provider, array $secrets = [], array $settings = []): array
{
    $organization = Organization::factory()->create();
    $providerModel = PaymentProvider::factory()->forOrganization($organization)->create([
        'type' => 'PaymentProviders::'.ucfirst($provider).'Provider',
        'code' => $provider,
        'name' => ucfirst($provider),
        'secrets' => $secrets,
        'settings' => $settings,
    ]);

    return [$organization, $providerModel];
}

function legsInvoice(Organization $organization, PaymentProvider $providerModel, array $attributes = []): array
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $customer->update(['payment_provider' => $providerModel->code, 'payment_provider_code' => $providerModel->code]);
    $providerCustomer = $customer->paymentProviderCustomers()->create([
        'payment_provider_id' => $providerModel->id,
        'type' => 'PaymentProviderCustomers::'.ucfirst($providerModel->paymentType()).'Customer',
        'provider_customer_id' => 'prov_cus_1',
        'organization_id' => $organization->id,
        'settings' => [],
    ]);

    $invoice = Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')
        ->create(array_merge([
            'total_amount_cents' => 1000,
            'currency' => 'USD',
            'ready_for_payment_processing' => true,
        ], $attributes));

    return [$customer, $invoice, $providerCustomer];
}

// -- CreatePaymentFactory legs --------------------------------------------------

it('creates the adyen payment through the checkout API with the stored payment method', function (): void {
    [$organization, $provider] = legsOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, $invoice, $providerCustomer] = legsInvoice($organization, $provider);

    $payment = Payment::factory()->pending()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'USD',
    ]);

    Http::fake([
        'checkout-test.adyen.com/*/paymentMethods' => Http::response([
            'storedPaymentMethods' => [['id' => 'stored_pm_1']],
        ]),
        'checkout-test.adyen.com/*/payments' => Http::response([
            'pspReference' => 'psp_new_1',
            'resultCode' => 'Authorised',
        ]),
    ]);

    $result = CreatePaymentFactory::newInstance('adyen', $payment, 'ref inv 1', ['invoice_type' => 'subscription'])->execute();

    expect($result->success())->toBeTrue();

    $payment->refresh();
    expect($payment->provider_payment_id)->toBe('psp_new_1')
        ->and($payment->status)->toBe('Authorised')
        ->and($payment->payablePaymentStatus())->toBe('succeeded');

    expect($providerCustomer->refresh()->getFromSettings('payment_method_id'))->toBe('stored_pm_1');

    Http::assertSent(function ($request) use ($payment): bool {
        if (str_contains($request->url(), '/payments') && ! str_contains($request->url(), 'paymentMethods')) {
            return $request['shopperInteraction'] === 'ContAuth'
                && $request['recurringProcessingModel'] === 'UnscheduledCardOnFile'
                && $request['merchantAccount'] === 'LagoMerchant'
                && $request->header('idempotency-key')[0] === 'payment-'.$payment->id;
        }

        return str_contains($request->url(), 'paymentMethods');
    });
});

it('fails the payment on an adyen error response', function (): void {
    [$organization, $provider] = legsOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, $invoice, $providerCustomer] = legsInvoice($organization, $provider);

    $payment = Payment::factory()->pending()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'USD',
    ]);

    Http::fake([
        'checkout-test.adyen.com/*' => Http::sequence()
            ->push(['storedPaymentMethods' => [['id' => 'stored_pm_1']]])
            ->push(['message' => 'Refused', 'errorType' => 'refused'], 422),
    ]);

    $result = CreatePaymentFactory::newInstance('adyen', $payment, 'ref', [])->execute();

    expect($result->failure())->toBeTrue();

    $payment->refresh();
    expect($payment->status)->toBe('failed')
        ->and($payment->payablePaymentStatus())->toBe('failed');
});

it('truncates a long adyen reference keeping the tail', function (): void {
    [$organization, $provider] = legsOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, $invoice, $providerCustomer] = legsInvoice($organization, $provider);

    $payment = Payment::factory()->pending()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'USD',
    ]);

    $longReference = str_repeat('x', 100).'-INV-123';

    Http::fake([
        'checkout-test.adyen.com/*/paymentMethods' => Http::response(['storedPaymentMethods' => [['id' => 'pm']]]),
        'checkout-test.adyen.com/*/payments' => Http::response(['pspReference' => 'p', 'resultCode' => 'Authorised']),
    ]);

    CreatePaymentFactory::newInstance('adyen', $payment, $longReference, [])->callOrFail();

    Http::assertSent(function ($request) use ($longReference): bool {
        if (str_contains($request->url(), '/payments') && ! str_contains($request->url(), 'paymentMethods')) {
            return $request['reference'] === substr($longReference, -80);
        }

        return true;
    });
});

it('creates the gocardless payment from the customer mandate fetched from the API', function (): void {
    [$organization, $provider] = legsOrganization('gocardless', ['access_token' => 'token']);
    [$customer, $invoice, $providerCustomer] = legsInvoice($organization, $provider);

    $payment = Payment::factory()->pending()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'USD',
    ]);

    Http::fake([
        'api-sandbox.gocardless.com/mandates?*' => Http::response([
            'mandates' => [['id' => 'md_1', 'links' => ['customer' => 'prov_cus_1']]],
        ]),
        'api-sandbox.gocardless.com/payments' => Http::response([
            'payments' => ['id' => 'gc_pay_1', 'status' => 'pending_submission'],
        ]),
    ]);

    $result = CreatePaymentFactory::newInstance('gocardless', $payment, 'ref', ['invoice_type' => 'subscription'])->callOrFail();

    expect($result->success())->toBeTrue();

    $payment->refresh();
    expect($payment->provider_payment_id)->toBe('gc_pay_1')
        ->and($payment->status)->toBe('pending_submission')
        ->and($payment->payablePaymentStatus())->toBe('processing')
        ->and($providerCustomer->refresh()->getFromSettings('provider_mandate_id'))->toBe('md_1');
});

it('fails the gocardless payment when no mandate exists', function (): void {
    [$organization, $provider] = legsOrganization('gocardless', ['access_token' => 'token']);
    [$customer, $invoice, $providerCustomer] = legsInvoice($organization, $provider);

    $payment = Payment::factory()->pending()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'USD',
    ]);

    Http::fake([
        'api-sandbox.gocardless.com/*' => Http::response(['mandates' => []]),
    ]);

    $result = CreatePaymentFactory::newInstance('gocardless', $payment, 'ref', [])->execute();

    expect($result->failure())->toBeTrue()
        ->and($payment->refresh()->status)->toBe('failed');
});

it('treats the cashfree payment leg as a no-op', function (): void {
    [$organization, $provider] = legsOrganization('cashfree', ['client_id' => 'cid', 'client_secret' => 'csecret']);
    [$customer, $invoice, $providerCustomer] = legsInvoice($organization, $provider);

    $payment = Payment::factory()->pending()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'USD',
    ]);

    $result = CreatePaymentFactory::newInstance('cashfree', $payment, 'ref', [])->callOrFail();

    expect($result->success())->toBeTrue()
        ->and($payment->refresh()->status)->toBe('pending');
});

it('creates the moneyhash payment intent through the intent API', function (): void {
    [$organization, $provider] = legsOrganization('moneyhash', ['api_key' => 'mh_key'], ['flow_id' => 'flow12']);
    [$customer, $invoice, $providerCustomer] = legsInvoice($organization, $provider);

    $payment = Payment::factory()->pending()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'USD',
    ]);

    Http::fake([
        'staging-web.moneyhash.io/api/v1.1/payments/intent/' => Http::response([
            'data' => ['id' => 'mh_int_9', 'status' => 'PROCESSED'],
        ]),
    ]);

    $result = CreatePaymentFactory::newInstance('moneyhash', $payment, 'ref', [])->callOrFail();

    expect($result->success())->toBeTrue();

    $payment->refresh();
    expect($payment->provider_payment_id)->toBe('mh_int_9')
        ->and($payment->status)->toBe('PROCESSED')
        ->and($payment->payablePaymentStatus())->toBe('succeeded');

    Http::assertSent(function ($request): bool {
        return $request['merchant_initiated'] === true
            && $request['payment_type'] === 'UNSCHEDULED'
            && $request['custom_fields']['lago_mit'] === true
            && $request->header('x-Api-Key')[0] === 'mh_key';
    });
});

// -- Customers legs -------------------------------------------------------------

it('syncs the adyen connection by queueing the create job', function (): void {
    [$organization, $provider] = legsOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    $customer = Customer::factory()->forOrganization($organization)->create([
        'payment_provider' => 'adyen',
        'payment_provider_code' => $provider->code,
    ]);

    Queue::fake();

    PaymentBillingConfigurationService::call(
        customer: $customer,
        params: ['billing_configuration' => [
            'payment_provider' => 'adyen',
            'sync_with_provider' => true,
        ]],
        newCustomer: true,
    );

    Queue::assertPushed(AdyenCreateCustomerJob::class);

    $providerCustomer = $customer->paymentProviderCustomers()->first();
    expect($providerCustomer)->not->toBeNull()
        ->and($providerCustomer->type)->toBe('PaymentProviderCustomers::AdyenCustomer');
});

it('keeps the cashfree connection local without a provider-side create', function (): void {
    [$organization, $provider] = legsOrganization('cashfree', ['client_id' => 'cid', 'client_secret' => 'csecret']);
    $customer = Customer::factory()->forOrganization($organization)->create([
        'payment_provider' => 'cashfree',
        'payment_provider_code' => $provider->code,
    ]);

    Queue::fake();

    PaymentBillingConfigurationService::call(
        customer: $customer,
        params: ['billing_configuration' => [
            'payment_provider' => 'cashfree',
            'sync_with_provider' => true,
        ]],
        newCustomer: true,
    );

    Queue::assertNothingPushed();

    $providerCustomer = $customer->paymentProviderCustomers()->first();
    expect($providerCustomer)->not->toBeNull()
        ->and($providerCustomer->type)->toBe('PaymentProviderCustomers::CashfreeCustomer');
});

it('syncs the gocardless connection by queueing the create job', function (): void {
    [$organization, $provider] = legsOrganization('gocardless', ['access_token' => 'token']);
    $customer = Customer::factory()->forOrganization($organization)->create([
        'payment_provider' => 'gocardless',
        'payment_provider_code' => $provider->code,
    ]);

    Queue::fake();

    PaymentBillingConfigurationService::call(
        customer: $customer,
        params: ['billing_configuration' => [
            'payment_provider' => 'gocardless',
            'sync_with_provider' => true,
        ]],
        newCustomer: true,
    );

    Queue::assertPushed(GocardlessCreateCustomerJob::class);
});

it('syncs the moneyhash connection by queueing the create job', function (): void {
    [$organization, $provider] = legsOrganization('moneyhash', ['api_key' => 'mh_key'], ['flow_id' => 'flow12']);
    $customer = Customer::factory()->forOrganization($organization)->create([
        'payment_provider' => 'moneyhash',
        'payment_provider_code' => $provider->code,
    ]);

    Queue::fake();

    PaymentBillingConfigurationService::call(
        customer: $customer,
        params: ['billing_configuration' => [
            'payment_provider' => 'moneyhash',
            'sync_with_provider' => true,
        ]],
        newCustomer: true,
    );

    Queue::assertPushed(MoneyhashCreateCustomerJob::class);
});

it('queues the adyen checkout url job when a connection receives an id with sync off', function (): void {
    [$organization, $provider] = legsOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    $customer = Customer::factory()->forOrganization($organization)->create([
        'payment_provider' => 'adyen',
        'payment_provider_code' => $provider->code,
    ]);

    $providerCustomer = $customer->paymentProviderCustomers()->create([
        'payment_provider_id' => $provider->id,
        'type' => 'PaymentProviderCustomers::AdyenCustomer',
        'organization_id' => $organization->id,
        'settings' => ['sync_with_provider' => false],
    ]);

    Queue::fake();

    $result = PaymentBillingConfigurationService::call(
        customer: $customer,
        params: ['billing_configuration' => ['payment_provider' => 'adyen', 'provider_customer_id' => 'shopper_1']],
        newCustomer: false,
    );

    expect($result->success())->toBeTrue();

    Queue::assertPushed(AdyenCheckoutUrlJob::class);

    expect($providerCustomer->refresh()->provider_customer_id)->toBe('shopper_1');
});

// -- payment_url (hosted checkout) ----------------------------------------------

it('generates the cashfree payment url for an invoice', function (): void {
    [$organization, $provider] = legsOrganization('cashfree', ['client_id' => 'cid', 'client_secret' => 'csecret']);
    [$customer, $invoice, $providerCustomer] = legsInvoice($organization, $provider);

    Http::fake([
        'sandbox.cashfree.com/pg/links' => Http::response(['link_url' => 'https://payments.cashfree.com/links/xyz']),
    ]);

    $result = GeneratePaymentUrlService::call(invoice: $invoice->loadMissing('customer'));

    expect($result->success())->toBeTrue()
        ->and($result->payment_url)->toBe('https://payments.cashfree.com/links/xyz');

    $intent = PaymentIntent::query()->where('invoice_id', $invoice->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->payment_url)->toBe('https://payments.cashfree.com/links/xyz');
});

it('validates the payment_url preconditions like Rails', function (): void {
    [$organization, $provider] = legsOrganization('cashfree', ['client_id' => 'cid', 'client_secret' => 'csecret']);
    [$customer, $invoice] = legsInvoice($organization, $provider);

    // No linked provider customer for the payment provider.
    $customer->paymentProviderCustomers()->delete();

    $result = GeneratePaymentUrlService::call(invoice: $invoice->loadMissing('customer'));

    expect($result->failure())->toBeTrue();

    // Rails: gocardless answers invalid_payment_provider.
    $gcOrganization = Organization::factory()->create();
    $gcProvider = PaymentProvider::factory()->forOrganization($gcOrganization)->create([
        'type' => 'PaymentProviders::GocardlessProvider', 'code' => 'gocardless', 'name' => 'GoCardless',
        'secrets' => ['access_token' => 't'], 'settings' => [],
    ]);
    [$gcCustomer, $gcInvoice] = legsInvoice($gcOrganization, $gcProvider);

    $gcResult = GeneratePaymentUrlService::call(invoice: $gcInvoice->loadMissing('customer'));

    expect($gcResult->failure())->toBeTrue();
});

it('serves the payment_url route with an api key', function (): void {
    [$organization, $provider] = legsOrganization('cashfree', ['client_id' => 'cid', 'client_secret' => 'csecret']);
    [$customer, $invoice] = legsInvoice($organization, $provider);

    $apiKey = ApiKey::factory()->forOrganization($organization)->create();

    Http::fake([
        'sandbox.cashfree.com/pg/links' => Http::response(['link_url' => 'https://payments.cashfree.com/links/route']),
    ]);

    $this->postJson("/api/v1/invoices/{$invoice->id}/payment_url", [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertJsonPath('invoice_payment_details.payment_url', 'https://payments.cashfree.com/links/route');
});
