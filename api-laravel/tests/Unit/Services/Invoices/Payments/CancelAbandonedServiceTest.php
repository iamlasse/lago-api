<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Models\PaymentProvider;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Http;
use App\Services\PaymentProviders\Stripe\RateLimitError;
use App\Services\Invoices\Payments\CancelAbandonedService;

uses()->group('ledger:svc:Invoices.Payments.CancelAbandonedService');

/**
 * Port of Rails' spec/services/invoices/payments/cancel_abandoned_service_spec.rb.
 * Rails stubs RetrieveService/CancelPaymentService; here the real services
 * run against Http::fake'd Stripe (the same fidelity bar as the ported
 * Stripe services tests).
 */

/**
 * Builds org + customer + finalized/pending invoice + Stripe provider + an
 * abandoned 3DS-redirect payment. Returns [$invoice, $payment, $provider].
 */
function abandonedSetup(array $paymentOverrides = [], array $invoiceOverrides = []): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->create();

    $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create(array_merge([
        'status' => InvoiceStatus::Finalized,
        'payment_status' => InvoicePaymentStatus::Pending->value,
        'ready_for_payment_processing' => false,
    ], $invoiceOverrides));

    $payment = Payment::factory()->forInvoice($invoice)->create(array_merge([
        'payment_provider_id' => $provider->id,
        'status' => 'requires_action',
        'payable_payment_status' => 'processing',
        'updated_at' => now()->subDays(2),
        'provider_payment_data' => ['type' => 'redirect_to_url'],
        'provider_payment_id' => 'pi_123',
    ], $paymentOverrides));

    return [$invoice, $payment, $provider];
}

/** Fakes Stripe retrieve (live intent) + cancel endpoints. */
function fakeStripeIntent(string $liveStatus, string $liveMethod, array $cancelOverrides = []): void
{
    Http::fake([
        // Trailing * — the retrieve URL carries the expand[] query string.
        'api.stripe.com/v1/payment_intents/pi_123*' => function ($request) use ($liveStatus, $liveMethod, $cancelOverrides) {
            if ($request->method() === 'GET') {
                if ($liveStatus === '@401') {
                    return Http::response(['error' => ['type' => 'authentication_error', 'message' => 'bad key']], 401);
                }
                if ($liveStatus === '@429') {
                    return Http::response(['error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429);
                }

                return Http::response([
                    'id' => 'pi_123',
                    'object' => 'payment_intent',
                    'status' => $liveStatus,
                    'payment_method' => ['id' => 'pm_1', 'type' => $liveMethod],
                ]);
            }

            // POST .../cancel — the provider webhook that would normally
            // land the real status "arrives" through this response.
            return Http::response(array_merge([
                'id' => 'pi_123',
                'object' => 'payment_intent',
                'status' => 'canceled',
            ], $cancelOverrides));
        },
    ]);
}

it('cancels the payment at the provider and makes the invoice payable again', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [$invoice, $payment] = abandonedSetup();

    $result = CancelAbandonedService::call(payment: $payment);

    expect($result->success())->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/payment_intents/pi_123/cancel'));

    expect($payment->refresh()->status)->toBe('canceled');
    expect($payment->refresh()->payablePaymentStatus())->toBe('failed');

    // The invoice payment status is left to the provider webhook; only the
    // re-arm flag flips.
    expect($invoice->refresh()->paymentStatusEnum())->toBe(InvoicePaymentStatus::Pending);
    expect($invoice->refresh()->ready_for_payment_processing)->toBeTrue();
});

it('leaves the invoice locked when the customer completes the payment while we are cancelling', function (): void {
    // The provider refuses an intent that has just succeeded, and its
    // webhook lands the real status on both records before this service
    // reloads them.
    fakeStripeIntent('requires_action', 'card', ['status' => 'succeeded']);
    [$invoice, $payment] = abandonedSetup();

    CancelAbandonedService::call(payment: $payment);

    expect($invoice->refresh()->ready_for_payment_processing)->toBeFalse();
    expect($invoice->refresh()->paymentStatusEnum())->toBe(InvoicePaymentStatus::Pending);
});

it('leaves the invoice locked when the provider refuses the cancellation', function (): void {
    fakeStripeIntent('requires_action', 'card');
    // No stored intent id: the provider dispatcher returns early — the
    // payment stays processing, so nothing unlocks.
    [, $payment] = abandonedSetup(['provider_payment_id' => null]);
    $invoice = $payment->payable;

    CancelAbandonedService::call(payment: $payment);

    expect($invoice->refresh()->ready_for_payment_processing)->toBeFalse();
});

it('does not cancel a bank transfer waiting on the wire', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup(['provider_payment_data' => ['type' => 'customer_balance']]);

    CancelAbandonedService::call(payment: $payment);

    // Not a redirect: the service never even reads the intent.
    Http::assertNothingSent();
});

it('does not cancel a payment redirected minutes ago', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup(['updated_at' => now()->subMinutes(5)]);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
});

it('does not cancel when the row is old but the redirect is fresh', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup([
        'created_at' => now()->subMonths(6),
        'updated_at' => now()->subHour(),
    ]);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
});

it('does not cancel a payment that already settled', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup(['payable_payment_status' => 'succeeded']);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
});

it('does not cancel a payment awaiting capture', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup(['status' => 'requires_capture']);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
});

it('brings the payment in line with the provider when the intent is already canceled', function (): void {
    fakeStripeIntent('canceled', 'card');
    [$invoice, $payment] = abandonedSetup();

    CancelAbandonedService::call(payment: $payment);

    // No cancel POST — do not try to cancel what is already gone.
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');

    expect($payment->refresh()->payablePaymentStatus())->toBe('failed');
    expect($invoice->refresh()->ready_for_payment_processing)->toBeTrue();
});

it('releases the invoice when the provider waits for a new payment method', function (): void {
    fakeStripeIntent('requires_payment_method', 'card');
    [$invoice, $payment] = abandonedSetup();

    CancelAbandonedService::call(payment: $payment);

    expect($payment->refresh()->payablePaymentStatus())->toBe('failed');
    expect($invoice->refresh()->ready_for_payment_processing)->toBeTrue();
});

it('does not cancel when the provider says the payment is not a card', function (): void {
    fakeStripeIntent('requires_action', 'crypto');
    [, $payment] = abandonedSetup();

    CancelAbandonedService::call(payment: $payment);

    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});

it('does not cancel when the provider says the customer has paid', function (): void {
    fakeStripeIntent('succeeded', 'card');
    [$invoice, $payment] = abandonedSetup();

    CancelAbandonedService::call(payment: $payment);

    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
    expect($invoice->refresh()->ready_for_payment_processing)->toBeFalse();
});

it('surfaces a hard cancel failure instead of swallowing it', function (): void {
    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_123*' => function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => 'pi_123', 'status' => 'requires_action', 'payment_method' => ['type' => 'card']]);
            }

            return Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'nope']], 404);
        },
    ]);
    [, $payment] = abandonedSetup();

    // try/catch, not ->throws: the Stripe error taxonomy lives inside
    // Client.php, so the classes are not autoloadable by name and Pest's
    // ->throws would treat the class-string as a message.
    try {
        CancelAbandonedService::call(payment: $payment);
        $this->fail('Expected the invalid request error to propagate');
    } catch (App\Services\PaymentProviders\Stripe\InvalidRequestError $e) {
        expect($e->getMessage())->toBe('nope');
        expect($e->code())->toBe('resource_missing');
    }
});

it('does nothing and does not fail when the provider rejects the stored key', function (): void {
    fakeStripeIntent('@401', 'card');
    [$invoice, $payment] = abandonedSetup();

    $result = CancelAbandonedService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    expect($invoice->refresh()->ready_for_payment_processing)->toBeFalse();
});

it('lets the rate limit through so the job retries instead of skipping the payment', function (): void {
    fakeStripeIntent('@429', 'card');
    [, $payment] = abandonedSetup();

    // try/catch, not ->throws: RateLimitError is declared in Client.php and
    // is not autoloadable by name (Pest's ->throws needs class_exists).
    try {
        CancelAbandonedService::call(payment: $payment);
        $this->fail('Expected the rate limit error to propagate');
    } catch (RateLimitError $e) {
        expect($e->getMessage())->toBe('slow down');
    }
});

it('leaves a payment gating a subscription activation to the activation flow', function (): void {
    fakeStripeIntent('requires_action', 'card');

    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->create();

    $invoice = Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create([
        'status' => InvoiceStatus::Open,
        'payment_status' => InvoicePaymentStatus::Pending->value,
        'ready_for_payment_processing' => false,
    ]);

    // Open invoice + subscription with a pending payment activation rule =
    // Rails' invoice#subscription_payment_gated? (the activation resolves
    // through its own flow, not this clock).
    $subscription = App\Models\Subscription::factory()->for($customer, 'customer')->create();
    App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
    ]);
    Database\Factories\Subscription\ActivationRuleFactory::new()
        ->for($subscription, 'subscription')
        ->create(['status' => 'pending']);

    $payment = Payment::factory()->forInvoice($invoice)->create([
        'payment_provider_id' => $provider->id,
        'status' => 'requires_action',
        'payable_payment_status' => 'processing',
        'updated_at' => now()->subDays(2),
        'provider_payment_data' => ['type' => 'redirect_to_url'],
        'provider_payment_id' => 'pi_123',
    ]);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
    expect($invoice->refresh()->ready_for_payment_processing)->toBeFalse();
});

it('skips payments predating the provider data column instead of raising', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup(['provider_payment_data' => null]);

    $result = CancelAbandonedService::call(payment: $payment);

    expect($result->success())->toBeTrue();
    Http::assertNothingSent();
});

it('leaves a redirect stale beyond the recovery window', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup(['updated_at' => now()->subMonths(6)]);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
});

it('does not cancel when the invoice was already paid', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup([], ['payment_status' => InvoicePaymentStatus::Succeeded->value]);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
});

it('does not cancel when the invoice was voided', function (): void {
    fakeStripeIntent('requires_action', 'card');
    [, $payment] = abandonedSetup([], ['status' => InvoiceStatus::Voided]);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
});

it('does not cancel when the payable is not an invoice', function (): void {
    fakeStripeIntent('requires_action', 'card');

    $organization = Organization::factory()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $paymentRequest = App\Models\PaymentRequest::factory()->forCustomer($customer)->create();

    $payment = Payment::factory()->create([
        'payable_type' => 'PaymentRequest',
        'payable_id' => $paymentRequest->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'payment_provider_id' => PaymentProvider::factory()->forOrganization($organization)->create()->id,
        'status' => 'requires_action',
        'payable_payment_status' => 'processing',
        'updated_at' => now()->subDays(2),
        'provider_payment_data' => ['type' => 'redirect_to_url'],
        'provider_payment_id' => 'pi_123',
    ]);

    CancelAbandonedService::call(payment: $payment);

    Http::assertNothingSent();
});
