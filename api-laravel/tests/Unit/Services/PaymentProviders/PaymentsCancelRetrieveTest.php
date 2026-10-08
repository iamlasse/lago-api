<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;
use App\Services\PaymentProviders\CancelPaymentService;
use App\Services\PaymentProviders\Stripe\InvalidRequestError;
use App\Services\PaymentProviders\Stripe\Payments\CancelService;
use App\Services\PaymentProviders\Stripe\Payments\RetrieveService;

uses()->group('ledger:svc:PaymentProviders.PaymentsCancelRetrieve');

/**
 * Unit coverage for the abandoned-payment recovery primitives the service
 * spec exercises end-to-end: Stripe RetrieveService / CancelService via
 * Http::fake, and the provider-agnostic CancelPaymentService dispatch.
 */
function cancelSetup(): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->create();
    $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();

    $payment = Payment::factory()->forInvoice($invoice)->create([
        'payment_provider_id' => $provider->id,
        'status' => 'requires_action',
        'payable_payment_status' => 'processing',
        'provider_payment_id' => 'pi_123',
    ]);

    return [$payment, $provider];
}

it('retrieves the live intent status and payment method type', function (): void {
    [, $provider] = cancelSetup();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_123*' => Http::response([
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'payment_method' => ['id' => 'pm_1', 'type' => 'card'],
        ]),
    ]);

    $payment = Payment::query()->where('provider_payment_id', 'pi_123')->first();
    $result = RetrieveService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    expect($result->status)->toBe('requires_action');
    expect($result->payment_method_type)->toBe('card');

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'expand'));
});

it('cancels the intent and follows the returned status onto the payment', function (): void {
    [$payment] = cancelSetup();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_123/cancel' => Http::response([
            'id' => 'pi_123', 'object' => 'payment_intent', 'status' => 'canceled',
        ]),
    ]);

    $result = CancelService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    expect($payment->refresh()->status)->toBe('canceled');
    expect($payment->refresh()->payablePaymentStatus())->toBe('failed');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['cancellation_reason'] === 'abandoned');
});

it('treats payment_intent_unexpected_state as a successful no-op', function (): void {
    [$payment] = cancelSetup();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_123/cancel' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'already succeeded'],
        ], 400),
    ]);

    $result = CancelService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    // The Payment record is left untouched.
    expect($payment->refresh()->status)->toBe('requires_action');
});

it('propagates other invalid request error codes', function (): void {
    [$payment] = cancelSetup();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_123/cancel' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'nope'],
        ], 404),
    ]);

    // try/catch, not ->throws: the Stripe error taxonomy lives inside
    // Client.php, so the classes are not autoloadable by name.
    try {
        CancelService::call(payment: $payment);
        $this->fail('Expected the invalid request error to propagate');
    } catch (InvalidRequestError $e) {
        expect($e->getMessage())->toBe('nope');
    }
});

it('skips the cancel when the payment has no provider', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();

    $orphan = Payment::factory()->forInvoice($invoice)->create([
        'payment_provider_id' => null,
        'status' => 'requires_action',
        'payable_payment_status' => 'processing',
        'provider_payment_id' => 'pi_x',
    ]);

    $result = CancelPaymentService::call(payment: $orphan);

    expect($result->success())->toBeTrue();
    Http::assertNothingSent();
});

it('skips the cancel when the intent id is blank', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->create();
    $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();

    $payment = Payment::factory()->forInvoice($invoice)->create([
        'payment_provider_id' => $provider->id,
        'provider_payment_id' => null,
    ]);

    $result = CancelPaymentService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    Http::assertNothingSent();
});

it('skips the cancel when the payment already succeeded', function (): void {
    [$payment] = cancelSetup();

    $payment->update(['payable_payment_status' => 'succeeded']);

    $result = CancelPaymentService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    Http::assertNothingSent();
});

it('dispatches the Stripe cancel for a Stripe provider payment', function (): void {
    [$payment] = cancelSetup();

    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_123/cancel' => Http::response([
            'id' => 'pi_123', 'status' => 'canceled',
        ]),
    ]);

    $result = CancelPaymentService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    expect($payment->refresh()->payablePaymentStatus())->toBe('failed');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/payment_intents/pi_123/cancel'));
});

it('logs and skips providers without a dedicated cancel service', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->create([
        'type' => 'PaymentProviders::CashfreeProvider',
        'code' => 'cashfree',
        'name' => 'Cashfree',
    ]);
    $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();
    $payment = Payment::factory()->forInvoice($invoice)->create([
        'payment_provider_id' => $provider->id,
        'payable_payment_status' => 'processing',
        'provider_payment_id' => 'cf_123',
    ]);

    $result = CancelPaymentService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    expect($payment->refresh()->payablePaymentStatus())->toBe('processing');
});
