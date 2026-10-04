<?php

declare(strict_types=1);

uses()->group('ledger:services:stripe');

use App\Models\Payment;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Models\PaymentProviderCustomer;
use App\Services\PaymentProviders\Stripe\Client;
use App\Jobs\PaymentProviders\StripeRegisterWebhookJob;
use App\Services\PaymentProviders\Stripe\RegisterService;
use App\Services\PaymentProviders\Stripe\RegisterWebhookService;
use App\Services\PaymentProviders\Stripe\Customers\CreateOrUpdateService;
use App\Services\Invoices\Payments\StripeService as InvoicePaymentsStripeService;
use App\Services\PaymentProviderCustomers\StripeService as StripeCustomerSyncService;
use App\Services\PaymentProviders\Stripe\Payments\CreateService as StripePaymentsCreateService;

/**
 * Port of Rails' spec/services/payment_providers/stripe_service_spec.rb +
 * spec/services/payment_providers/stripe/{register_webhook_service,
 * payments/create_service, customers/create_service}_spec.rb — Stripe API
 * calls are faked via Http::fake against api.stripe.com (the Laravel
 * Http client replaces the stripe gem).
 */
function stripeServiceProvider(Organization $organization): PaymentProvider
{
    return PaymentProvider::factory()->forOrganization($organization)->create([
        'code' => 'stripe',
        'name' => 'Stripe',
        'settings' => ['webhook_secret' => 'whsec_existing'],
    ]);
}

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

// -- RegisterService (org connects Stripe) --------------------------------------

it('creates a stripe provider and queues the webhook registration', function (): void {
    $organization = Organization::factory()->create();

    $result = RegisterService::call(
        organizationId: $organization->id,
        args: [
            'secret_key' => 'sk_test_123',
            'code' => 'stripe',
            'name' => 'Stripe',
            'success_redirect_url' => 'https://example.com/ok',
            'supports_3ds' => true,
            'require_terms_of_service_consent' => true,
        ],
    );

    expect($result->success())->toBeTrue()
        ->and($result->stripe_provider->secretKey())->toBe('sk_test_123')
        ->and($result->stripe_provider->supports3ds())->toBeTrue()
        ->and($result->stripe_provider->requireTermsOfServiceConsent())->toBeTrue();

    Queue::assertPushed(StripeRegisterWebhookJob::class);
});

it('does not overwrite the stored secret key on update', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    $provider->setSecretKey('sk_test_original');
    $provider->save();

    $result = RegisterService::call(
        organizationId: $organization->id,
        args: ['secret_key' => 'sk_test_new', 'code' => 'stripe', 'name' => 'Stripe Renamed'],
    );

    expect($result->success())->toBeTrue()
        ->and($result->stripe_provider->id)->toBe($provider->id)
        ->and($result->stripe_provider->secretKey())->toBe('sk_test_original')
        ->and($result->stripe_provider->name)->toBe('Stripe Renamed');

    Queue::assertNotPushed(StripeRegisterWebhookJob::class);
});

it('rejects a new provider without a secret key', function (): void {
    $organization = Organization::factory()->create();

    $result = RegisterService::call(
        organizationId: $organization->id,
        args: ['code' => 'stripe', 'name' => 'Stripe'],
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['secret_key' => ['value_is_mandatory']]);
});

// -- RegisterWebhookService (Stripe API) ------------------------------------------

it('registers a stripe webhook endpoint and stores id + secret', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);

    Http::fake([
        'api.stripe.com/v1/webhook_endpoints' => Http::response([
            'id' => 'we_123',
            'secret' => 'whsec_new',
            'url' => 'https://lago.test/webhooks/stripe/'.$organization->id,
        ], 200),
    ]);

    $result = RegisterWebhookService::callBang(paymentProvider: $provider);

    expect($result->success())->toBeTrue()
        ->and($provider->refresh()->webhookId())->toBe('we_123')
        ->and($provider->refresh()->webhookSecret())->toBe('whsec_new');

    Http::assertSent(function ($request) use ($organization, $provider): bool {
        $body = $request->body();

        return str_contains($body, 'enabled_events%5B%5D=payment_intent.succeeded')
            && str_contains($body, 'enabled_events%5B%5D=payment_intent.payment_failed')
            && str_contains($body, 'api_version='.urlencode(Client::API_VERSION))
            && str_contains($body, urlencode("/webhooks/stripe/{$organization->id}?code={$provider->code}"));
    });
});

// -- Customer sync (Stripe API) ---------------------------------------------------

it('creates the customer on stripe and stores the provider customer id', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    $customer = Customer::factory()->forOrganization($organization)->create([
        'payment_provider' => 'stripe',
        'payment_provider_code' => 'stripe',
    ]);
    $providerCustomer = PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'type' => 'PaymentProviderCustomers::StripeCustomer',
        'provider_customer_id' => null,
        'settings' => ['sync_with_provider' => true, 'provider_payment_methods' => ['card']],
    ]);

    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new', 'object' => 'customer'], 200),
    ]);

    $result = StripeCustomerSyncService::callBang(action: 'create', providerCustomer: $providerCustomer);

    expect($result->success())->toBeTrue()
        ->and($providerCustomer->refresh()->provider_customer_id)->toBe('cus_new');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer '.($provider->secretKey()))
        && str_contains($request->body(), 'metadata%5Blago_customer_id%5D='.rawurlencode($customer->id))
        && str_contains($request->body(), 'metadata%5Bcustomer_id%5D='.rawurlencode($customer->external_id)));

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'customer.payment_provider_created');
});

it('keeps the provider customer untouched when stripe errors', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    $customer = Customer::factory()->forOrganization($organization)->create([
        'payment_provider' => 'stripe',
        'payment_provider_code' => 'stripe',
    ]);
    $providerCustomer = PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'type' => 'PaymentProviderCustomers::StripeCustomer',
        'provider_customer_id' => null,
        'settings' => ['sync_with_provider' => true, 'provider_payment_methods' => ['card']],
    ]);

    Http::fake([
        'api.stripe.com/v1/customers' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'email invalid', 'code' => 'invalid_email'],
        ], 400),
    ]);

    $result = StripeCustomerSyncService::call(action: 'create', providerCustomer: $providerCustomer);

    expect($result->success())->toBeTrue() // Rails: swallow + error webhook
        ->and($providerCustomer->refresh()->provider_customer_id)->toBeNull();

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'customer.payment_provider_error');
});

// -- CreateOrUpdateService (billing_configuration leg) -----------------------------

it('creates the provider customer connection and queues the sync', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    $customer = Customer::factory()->forOrganization($organization)->create();

    $result = CreateOrUpdateService::call(
        customer: $customer,
        paymentProviderId: $provider->id,
        params: ['sync_with_provider' => true],
    );

    expect($result->success())->toBeTrue()
        ->and($result->provider_customer->type)->toBe('PaymentProviderCustomers::StripeCustomer')
        ->and($result->provider_customer->getFromSettings('provider_payment_methods'))->toBe(['card'])
        ->and($result->provider_customer->getFromSettings('sync_with_provider'))->toBeTrue();

    Queue::assertPushed(App\Jobs\PaymentProviders\StripeCreateCustomerJob::class);
});

// -- Payments\CreateService (payment intent) ---------------------------------------

function stripeChargeSetup(Organization $organization, PaymentProvider $provider): array
{
    $customer = Customer::factory()->forOrganization($organization)->create([
        'payment_provider' => 'stripe',
        'payment_provider_code' => 'stripe',
        'country' => 'FR',
    ]);
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')
        ->create(['total_amount_cents' => 1000, 'ready_for_payment_processing' => true]);
    $providerCustomer = PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'type' => 'PaymentProviderCustomers::StripeCustomer',
        'provider_customer_id' => 'cus_123',
        'settings' => ['provider_payment_methods' => ['card']],
    ]);
    $payment = Payment::factory()->forInvoice($invoice)->forCustomer($customer)->pending()->create([
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'provider_payment_id' => 'pi_webhook_test',
        'amount_cents' => 1000,
    ]);
    $payment->setRelation('paymentProviderCustomer', $providerCustomer);
    $payment->setRelation('payable', $invoice);
    $payment->setRelation('customer', $customer);

    return [$customer, $invoice, $providerCustomer, $payment];
}

it('confirms the payment intent and settles the payment', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    [, $invoice, , $payment] = stripeChargeSetup($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/customers/*' => Http::response([
            'id' => 'cus_123', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null],
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_ok', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000,
        ], 200),
    ]);

    $result = StripePaymentsCreateService::callBang(
        payment: $payment,
        reference: 'Lago - Invoice INV1',
        metadata: ['lago_invoice_id' => $invoice->id],
    );

    expect($result->success())->toBeTrue()
        ->and($payment->refresh()->status)->toBe('succeeded')
        ->and($payment->payablePaymentStatus())->toBe('succeeded')
        ->and($payment->provider_payment_id)->toBe('pi_ok');

    Http::assertSent(function ($request) use ($payment): bool {
        $body = $request->body();
        $c1 = str_contains($request->url(), '/v1/payment_intents');
        $c2 = $request->hasHeader('Idempotency-Key', 'payment-'.$payment->id);
        $c3 = str_contains($body, 'amount=1000');
        $c4 = str_contains($body, 'currency=eur');
        $c5 = str_contains($body, 'customer=cus_123');
        $c6 = str_contains($body, 'payment_method_types%5B%5D=card');
        $c7 = str_contains($body, 'confirm=true');
        $c8 = str_contains($body, 'off_session=true');
        $c9 = str_contains($body, 'metadata%5Blago_payment_id%5D='.rawurlencode($payment->id));
        $c10 = str_contains($body, 'return_url='.rawurlencode(PaymentProvider::STRIPE_SUCCESS_REDIRECT_URL));

        return $c1 && $c2 && $c3 && $c4 && $c5 && $c6 && $c7 && $c8 && $c9 && $c10;
    });
});

it('marks the payment failed on a card error keeping the intent id', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    [, $invoice, , $payment] = stripeChargeSetup($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/customers/*' => Http::response([
            'id' => 'cus_123', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null],
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'message' => 'Your card was declined.',
                'payment_intent' => ['id' => 'pi_declined'],
            ],
        ], 402),
    ]);

    $result = StripePaymentsCreateService::call(
        payment: $payment,
        reference: 'Lago - Invoice INV1',
        metadata: [],
    );

    expect($result->failure())->toBeTrue()
        ->and($result->error_code)->toBe('card_declined')
        ->and($payment->refresh()->status)->toBe('failed')
        ->and($payment->payablePaymentStatus())->toBe('failed')
        ->and($payment->error_code)->toBe('card_declined')
        ->and($payment->provider_payment_id)->toBe('pi_declined');
});

it('keeps the invoice pending when the amount is too small for stripe', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    [, , , $payment] = stripeChargeSetup($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/customers/*' => Http::response([
            'id' => 'cus_123', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null],
        ], 200),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'amount_too_small',
                'message' => 'Amount must be at least 50 cents',
            ],
        ], 400),
    ]);

    $result = StripePaymentsCreateService::call(
        payment: $payment,
        reference: 'Lago - Invoice INV1',
        metadata: [],
    );

    expect($result->failure())->toBeTrue()
        ->and($payment->refresh()->payablePaymentStatus())->toBe('pending');
});

// -- ExpirePaymentIntentsService ----------------------------------------------------

it('expires the provider customers active payment intents', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $providerCustomer = PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
    ]);
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();
    $intent = App\Models\PaymentIntent::factory()->forInvoice($invoice)->create([
        'expires_at' => now()->addHours(24),
    ]);

    // A different org's customer keeps its intent.
    $otherOrg = Organization::factory()->create();
    $otherCustomer = Customer::factory()->forOrganization($otherOrg)->create();
    $otherIntent = App\Models\PaymentIntent::factory()->forInvoice(
        App\Models\Invoice::factory()->for($otherCustomer, 'customer')->for($otherOrg, 'organization')->create(),
    )->create();

    $inner = 'select "invoices"."id" from "invoices" inner join "customers" on "customers"."id" = "invoices"."customer_id" where "customers"."id" in (select "id" from "payment_provider_customers" where "payment_provider_id" = ? and "deleted_at" is null)';
    $dbg = Illuminate\Support\Facades\DB::select($inner, [$provider->id]);
    $subqIds = Illuminate\Support\Facades\DB::select('select id::text as id from payment_provider_customers where payment_provider_id = ?', [$provider->id]);
    $joinedId = Illuminate\Support\Facades\DB::select('select c.id::text as id from invoices i join customers c on c.id = i.customer_id where i.id = ?', [$invoice->id]);
    $dbg2 = Illuminate\Support\Facades\DB::select('select id from payment_provider_customers where payment_provider_id = ? and deleted_at is null', [$provider->id]);
    $dbg3 = Illuminate\Support\Facades\DB::select('select c.id from invoices i join customers c on c.id = i.customer_id where i.id = ?', [$invoice->id]);
    $ppcRow = Illuminate\Support\Facades\DB::select('select id, customer_id from payment_provider_customers where payment_provider_id = ? limit 1', [$provider->id]);
    dump(json_encode(['joinNoSub' => count($dbg3), 'joinedCustId' => $dbg3[0]->id ?? null, 'ppcCustId' => $ppcRow[0]->customer_id ?? null, 'ppcRowExists' => count($ppcRow), 'testCust' => $customer->id, 'invCustAttr' => $invoice->customer_id]));

    $result = App\Services\PaymentProviders\Stripe\ExpirePaymentIntentsService::call(paymentProvider: $provider);

    expect($result->success())->toBeTrue()
        ->and($intent->refresh()->statusName())->toBe('expired')
        ->and($intent->expires_at->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($otherIntent->refresh()->statusName())->toBe('active');
});

// -- Webhook-driven invoice status (Invoices\Payments\StripeService) ---------------

it('advances the invoice payment_status from a webhook settlement', function (): void {
    $organization = Organization::factory()->create();
    $provider = stripeServiceProvider($organization);
    [$customer, $invoice, , $payment] = stripeChargeSetup($organization, $provider);

    $result = InvoicePaymentsStripeService::updatePaymentStatus(
        organizationId: $organization->id,
        status: 'succeeded',
        stripePayment: new App\Values\StripePayment(
            id: (string) $payment->provider_payment_id,
            status: 'succeeded',
            metadata: ['lago_customer_id' => $customer->id],
        ),
    );

    expect($result->success())->toBeTrue()
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('succeeded');

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment.succeeded');
});
