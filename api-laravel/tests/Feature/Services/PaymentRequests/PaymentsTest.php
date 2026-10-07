<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;
use App\Jobs\PaymentRequests\PaymentsCreateJob;
use App\Services\PaymentRequests\CreateService;
use App\Services\PaymentRequests\UpdateService;
use App\Services\PaymentRequests\Payments\AdyenService;
use App\Services\PaymentRequests\Payments\StripeService;
use App\Services\PaymentRequests\Payments\MoneyhashService;
use App\Services\PaymentRequests\Payments\GocardlessService;
use App\Services\PaymentRequests\Payments\GeneratePaymentUrlService;
use App\Services\PaymentProviders\Cashfree\Webhooks\PaymentLinkEventService;
use App\Services\PaymentProviders\Flutterwave\Webhooks\ChargeCompletedService;
use App\Services\PaymentRequests\Payments\CreateService as PaymentsCreateService;
use App\Services\PaymentProviders\Adyen\HandleEventService as AdyenHandleEventService;
use App\Services\PaymentProviders\Gocardless\HandleEventService as GocardlessHandleEventService;

/**
 * Ports of the payment-request payment specs
 * (spec/services/payment_requests/payments/*_spec.rb) — the payable arms:
 * the create flow (auto-charge), the provider update_payment_status legs,
 * the generate_payment_url flow, and the webhook wirings that dispatch on
 * lago_payable_type.
 */
function prOrganization(string $provider, array $secrets = [], array $settings = []): array
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

/**
 * A payment request over one overdue invoice, with the provider customer
 * connection in place.
 */
function prPayable(Organization $organization, PaymentProvider $providerModel, array $attributes = []): array
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $customer->update(['payment_provider' => $providerModel->code, 'payment_provider_code' => $providerModel->code]);

    $providerCustomer = $customer->paymentProviderCustomers()->create([
        'payment_provider_id' => $providerModel->id,
        'type' => 'PaymentProviderCustomers::'.ucfirst($providerModel->paymentType()).'Customer',
        'provider_customer_id' => 'prov_cus_1',
        'organization_id' => $organization->id,
        'settings' => ['provider_payment_methods' => ['card']],
    ]);

    $invoice = Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')
        ->create([
            'total_amount_cents' => 1000,
            'currency' => 'EUR',
            'ready_for_payment_processing' => true,
            'payment_overdue' => true,
        ]);

    $payable = PaymentRequest::factory()->forCustomer($customer)->create(array_merge([
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
    ], $attributes));
    $payable->invoices()->attach($invoice->id, [
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$customer, $invoice, $providerCustomer, $payable];
}

// -- PaymentRequests::UpdateService ----------------------------------------------

it('updates the payment request payment status and delivers the webhook', function (): void {
    [$organization] = prOrganization('stripe');
    $customer = Customer::factory()->forOrganization($organization)->create();
    $payable = PaymentRequest::factory()->forCustomer($customer)->create();

    $result = UpdateService::call(payable: $payable, params: [
        'payment_status' => 'succeeded',
        'ready_for_payment_processing' => false,
    ], webhookNotification: true);

    expect($result->success())->toBeTrue()
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded')
        ->and($payable->ready_for_payment_processing)->toBeFalse();

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_request.payment_status_updated');
});

it('rejects an invalid payment request payment status', function (): void {
    [$organization] = prOrganization('stripe');
    $customer = Customer::factory()->forOrganization($organization)->create();
    $payable = PaymentRequest::factory()->forCustomer($customer)->create();

    $result = UpdateService::call(payable: $payable, params: ['payment_status' => 'nope']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['payment_status'])->toBe(['value_is_invalid']);
});

// -- PaymentRequests::Payments::CreateService ------------------------------------

it('marks a zero-amount payment request as succeeded', function (): void {
    [$organization, $provider] = prOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $payable] = prPayable($organization, $provider, ['amount_cents' => 0]);

    $result = (new PaymentsCreateService(payable: $payable))->execute();

    expect($result->success())->toBeTrue()
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded');

    expect(Payment::query()->where('payable_type', 'PaymentRequest')->where('payable_id', $payable->id)->count())->toBe(0);
});

it('creates the payment and charges it through stripe', function (): void {
    [$organization, $provider] = prOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_pr_1',
            'status' => 'succeeded',
        ]),
    ]);

    $result = (new PaymentsCreateService(payable: $payable))->execute();

    expect($result->success())->toBeTrue();

    $payment = $result->payment;
    expect($payment->payable_type)->toBe('PaymentRequest')
        ->and($payment->payable_id)->toBe($payable->id)
        ->and($payment->amount_cents)->toBe(1000)
        ->and($payment->provider_payment_id)->toBe('pi_pr_1')
        ->and($payment->payablePaymentStatus())->toBe('succeeded');

    $payable->refresh();
    expect($payable->paymentStatus())->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum()->value)->toBe(1);

    Http::assertSent(function ($request) use ($payment): bool {
        $body = $request->body();
        $invoice = $payment->payable->invoices->first();
        $description = rawurlencode($invoice->billingEntity->name.' - Overdue invoices: '.$invoice->number);

        return str_contains($request->url(), '/v1/payment_intents')
            && $request->hasHeader('Idempotency-Key', 'payment-'.$payment->id)
            && str_contains($body, urlencode('metadata[lago_payable_type]').'=PaymentRequest')
            && str_contains($body, 'description='.$description);
    });
});

it('skips the charge when the payment request already succeeded', function (): void {
    [$organization, $provider] = prOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $payable] = prPayable($organization, $provider, ['payment_status' => 1]);

    $result = (new PaymentsCreateService(payable: $payable))->execute();

    expect($result->success())->toBeTrue()
        ->and($result->payment)->toBeNull();

    expect(Payment::query()->count())->toBe(0);
});

it('fails with not found when the customer has no payment provider', function (): void {
    [$organization, $provider] = prOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [$customer, , , $payable] = prPayable($organization, $provider);

    $customer->update(['payment_provider' => null, 'payment_provider_code' => null]);

    $result = (new PaymentsCreateService(payable: $payable))->execute();

    expect($result->failure())->toBeTrue()
        ->and($result->getError() instanceof App\Services\Failures\NotFoundFailure)->toBeTrue();
});

it('dispatches the async charge job from callAsync and the request creation flow', function (): void {
    [$organization, $provider] = prOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    (new PaymentsCreateService(payable: $payable))->callAsync();

    Queue::assertPushed(PaymentsCreateJob::class);

    // The payment request creation flow auto-charges the fresh request.
    config(['lago.license' => 'premium-license-token']);

    $result = (new CreateService($organization, [
        'external_customer_id' => $customer->external_id,
        'lago_invoice_ids' => [$invoice->id],
    ]))->execute();

    expect($result->success())->toBeTrue();

    Queue::assertPushed(PaymentsCreateJob::class, fn (PaymentsCreateJob $job): bool => $job->payable->id === $result->payment_request->id);
});

// -- Provider update_payment_status legs ------------------------------------------

it('moves the payment request through the adyen one-time settlement', function (): void {
    [$organization, $provider] = prOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    $payment = Payment::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'PaymentRequest',
        'payable_id' => $payable->id,
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'provider_payment_id' => 'psp_pr_1',
        'status' => 'pending',
        'payable_payment_status' => 'pending',
    ]);

    $result = AdyenService::updatePaymentStatus(
        providerPaymentId: 'psp_pr_1',
        status: 'Authorised',
    );

    expect($result->success())->toBeTrue()
        ->and($payment->refresh()->status)->toBe('Authorised')
        ->and($payment->payablePaymentStatus())->toBe('succeeded')
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum()->value)->toBe(1);
});

it('recreates the payment from the adyen one-time metadata', function (): void {
    [$organization, $provider] = prOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [, , , $payable] = prPayable($organization, $provider);

    $result = AdyenService::updatePaymentStatus(
        providerPaymentId: 'psp_pr_new',
        status: 'Authorised',
        metadata: ['payment_type' => 'one-time', 'lago_payable_id' => $payable->id],
    );

    expect($result->success())->toBeTrue()
        // Rails' recreated payment carries no provider_payment_id — only the
        // status legs are stamped on save.
        ->and($result->payment->payable_id)->toBe($payable->id)
        ->and($result->payment->payablePaymentStatus())->toBe('succeeded')
        ->and($payable->refresh()->payment_attempts)->toBe(1);
});

it('handles the adyen AUTHORISATION webhook for a payment request payable', function (): void {
    [$organization, $provider] = prOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    Payment::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'PaymentRequest',
        'payable_id' => $payable->id,
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'provider_payment_id' => 'psp_pr_auth',
        'status' => 'pending',
        'payable_payment_status' => 'pending',
    ]);

    $result = (new AdyenHandleEventService($organization, json_encode([
        'eventCode' => 'AUTHORISATION',
        'success' => 'true',
        'pspReference' => 'psp_pr_auth',
        'amount' => ['value' => 0],
        'additionalData' => [
            'metadata.payment_type' => 'one-time',
            'metadata.lago_payable_id' => $payable->id,
            'metadata.lago_payable_type' => 'PaymentRequest',
        ],
    ])))->execute();

    expect($result->success())->toBeTrue()
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded');
});

it('moves the payment request through the gocardless payment event', function (): void {
    [$organization, $provider] = prOrganization('gocardless', ['access_token' => 'gc_token']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    $payment = Payment::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'PaymentRequest',
        'payable_id' => $payable->id,
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'provider_payment_id' => 'gc_pay_pr_1',
        'status' => 'pending',
        'payable_payment_status' => 'pending',
    ]);

    $service = new GocardlessHandleEventService($provider, json_encode([
        'resource_type' => 'payments',
        'action' => 'paid_out',
        'links' => ['payment' => 'gc_pay_pr_1'],
        'metadata' => ['lago_payable_type' => 'PaymentRequest'],
    ]));

    $result = $service->execute();

    expect($result->success())->toBeTrue()
        ->and($payment->refresh()->status)->toBe('paid_out')
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded');
});

it('moves the payment request through the cashfree payment link event', function (): void {
    [$organization, $provider] = prOrganization('cashfree', ['client_id' => 'cf_id', 'client_secret' => 'cf_secret']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    $service = new PaymentLinkEventService($organization->id, json_encode([
        'data' => [
            'link_status' => 'PAID',
            'link_notes' => [
                'lago_payable_id' => $payable->id,
                'lago_payable_type' => 'PaymentRequest',
                'payment_type' => 'one-time',
            ],
            'link_amount_paid' => '10.00',
            'link_currency' => 'EUR',
        ],
    ]));

    $result = $service->execute();

    expect($result->success())->toBeTrue()
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded');
});

it('moves the payment request through the flutterwave charge completed event', function (): void {
    [$organization, $provider] = prOrganization('flutterwave', ['secret_key' => 'flw_sec']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    Http::fake([
        'api.flutterwave.com/v3/transactions/*/verify' => Http::response([
            'status' => 'success',
            'data' => ['id' => 777, 'status' => 'successful', 'amount' => 10.0, 'currency' => 'EUR', 'reference' => 'ref_1'],
        ]),
    ]);

    $service = new ChargeCompletedService($organization->id, json_encode([
        'event' => 'charge.completed',
        'data' => [
            'id' => 777,
            'status' => 'successful',
            'amount' => 10.0,
            'currency' => 'EUR',
            'tx_ref' => 'lago_payment_request_'.$payable->id,
            'meta' => [
                'lago_payable_id' => $payable->id,
                'lago_payable_type' => 'PaymentRequest',
            ],
        ],
    ]));

    $result = $service->execute();

    expect($result->success())->toBeTrue()
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded');
});

it('moves the payment request through the moneyhash webhook', function (): void {
    [$organization, $provider] = prOrganization('moneyhash', ['api_key' => 'mh_key']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    Payment::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'PaymentRequest',
        'payable_id' => $payable->id,
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'provider_payment_id' => 'mh_intent_1',
        'status' => 'PENDING',
        'payable_payment_status' => 'pending',
    ]);

    $result = MoneyhashService::updatePaymentStatus(
        organizationId: $organization->id,
        providerPaymentId: 'mh_intent_1',
        status: 'SUCCESSFUL',
        metadata: ['lago_payable_type' => 'PaymentRequest'],
    );

    expect($result->success())->toBeTrue()
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded');
});

// -- generate_payment_url flow ----------------------------------------------------

it('generates an adyen payment link for the payment request', function (): void {
    [$organization, $provider] = prOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, , , $payable] = prPayable($organization, $provider);

    Http::fake([
        'checkout-test.adyen.com/*/paymentLinks' => Http::response([
            'url' => 'https://pay.adyen.com/links/pr1',
        ]),
    ]);

    $result = (new GeneratePaymentUrlService($payable))->execute();

    expect($result->success())->toBeTrue()
        ->and($result->payment_url)->toBe('https://pay.adyen.com/links/pr1');

    Http::assertSent(fn($request): bool => $request['amount']['value'] === 1000
        && $request['reference'] === 'Overdue invoices'
        && $request['metadata']['lago_payable_type'] === 'PaymentRequest'
        && $request['metadata']['payment_type'] === 'one-time');
});

it('generates a stripe checkout url for the payment request', function (): void {
    [$organization, $provider] = prOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [$customer, $invoice, $providerCustomer, $payable] = prPayable($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response([
            'url' => 'https://checkout.stripe.com/session_1',
        ]),
    ]);

    $result = StripeService::generatePaymentUrl($payable);

    expect($result->success())->toBeTrue()
        ->and($result->payment_url)->toBe('https://checkout.stripe.com/session_1');

    Http::assertSent(function ($request): bool {
        $body = $request->body();

        return str_contains($request->url(), '/v1/checkout/sessions')
            && str_contains($body, 'mode=payment')
            && str_contains($body, urlencode('payment_intent_data[metadata][lago_payable_type]').'=PaymentRequest')
            && str_contains($body, urlencode('payment_intent_data[metadata][payment_type]').'=one-time')
            && str_contains($body, urlencode('line_items[][price_data][unit_amount]').'=1000');
    });
});

it('validates the generate payment url flow', function (): void {
    [$organization, $provider] = prOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, , , $payable] = prPayable($organization, $provider);

    // No linked payment provider.
    $customer->update(['payment_provider' => null, 'payment_provider_code' => null]);

    $result = (new GeneratePaymentUrlService($payable))->execute();
    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['base'])->toBe(['no_linked_payment_provider']);

    // Gocardless is invalid for payment URLs.
    $customer->update(['payment_provider' => 'gocardless', 'payment_provider_code' => 'gocardless']);
    $result = (new GeneratePaymentUrlService(PaymentRequest::query()->find($payable->id)))->execute();
    expect($result->getError()->messages['base'])->toBe(['invalid_payment_provider']);

    // A succeeded request is invalid.
    $customer->update(['payment_provider' => 'adyen', 'payment_provider_code' => 'adyen']);
    $succeeded = PaymentRequest::factory()->forCustomer($customer)->succeeded()->create();
    $result = (new GeneratePaymentUrlService($succeeded))->execute();
    expect($result->getError()->messages['base'])->toBe(['invalid_payment_status']);
});

it('rejects gocardless through the payment provider factory mapping', function (): void {
    expect(GeneratePaymentUrlService::serviceClass('gocardless'))->toBe(GocardlessService::class)
        ->and(GeneratePaymentUrlService::serviceClass('adyen'))->toBe(AdyenService::class)
        ->and(GeneratePaymentUrlService::serviceClass('unknown'))->toBeNull();
});
