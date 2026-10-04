<?php

declare(strict_types=1);

uses()->group('ledger:webhooks:stripe');

use App\Models\Payment;
use App\Models\Customer;
use App\Models\Organization;
use App\Values\StripePayment;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Queue;
use App\Services\Invoices\Payments\StripeService;
use App\Jobs\PaymentProviders\StripeHandleEventJob;

/**
 * Port of Rails' spec/requests/webhooks_controller_spec.rb (stripe leg) and
 * spec/services/payment_providers/stripe/handle_*_service_spec.rb —
 * signature verification (Stripe-Signature HMAC, 300s tolerance), the
 * payment_intent.succeeded / payment_failed / canceled transitions and the
 * invoice payment_status follow-up.
 */
function stripeSign(string $payload, string $secret, ?int $timestamp = null): string
{
    $t = $timestamp ?? time();
    $signature = hash_hmac('sha256', $t.'.'.$payload, $secret);

    return "t={$t},v1={$signature}";
}

function stripeWebhookOrganization(): array
{
    $organization = Organization::factory()->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->withWebhookSecret()->create();

    return [$organization, $provider];
}

function stripeWebhookInvoice(Organization $organization, PaymentProvider $provider, array $attributes = [])
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $customer->update(['payment_provider' => 'stripe', 'payment_provider_code' => $provider->code]);
    $customer->paymentProviderCustomers()->create([
        'payment_provider_id' => $provider->id,
        'type' => 'PaymentProviderCustomers::StripeCustomer',
        'provider_customer_id' => 'cus_123',
        'organization_id' => $organization->id,
        'settings' => ['provider_payment_methods' => ['card']],
    ]);

    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')
        ->create($attributes);

    return [$customer, $invoice];
}

function stripeWebhookEvent(array $overrides = []): array
{
    return [
        'id' => 'evt_1',
        'type' => 'payment_intent.succeeded',
        'livemode' => false,
        'data' => [
            'object' => array_merge([
                'id' => 'pi_123',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 1000,
                'metadata' => [],
            ], $overrides),
        ],
    ];
}

beforeEach(function (): void {
    Queue::fake();
});

// -- Signature verification ---------------------------------------------------

it('accepts a webhook with a valid Stripe signature and queues the event', function (): void {
    [$organization, $provider] = stripeWebhookOrganization();
    $event = stripeWebhookEvent();
    $payload = json_encode($event, JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/stripe/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSign($payload, $provider->webhookSecret()),
    ], $payload)->assertOk();

    Queue::assertPushed(StripeHandleEventJob::class);
});

it('rejects a webhook with an invalid signature', function (): void {
    [$organization, $provider] = stripeWebhookOrganization();
    $payload = json_encode(stripeWebhookEvent(), JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/stripe/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=deadbeef',
    ], $payload)->assertBadRequest();

    Queue::assertNotPushed(StripeHandleEventJob::class);
});

it('rejects a webhook whose timestamp is outside the tolerance window', function (): void {
    [$organization, $provider] = stripeWebhookOrganization();
    $payload = json_encode(stripeWebhookEvent(), JSON_THROW_ON_ERROR);
    // Rails: Stripe::Webhook::DEFAULT_TOLERANCE = 300s.
    $stale = stripeSign($payload, $provider->webhookSecret(), time() - 3600);

    $this->call('POST', "/webhooks/stripe/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $stale,
    ], $payload)->assertBadRequest();
});

it('rejects a webhook for an unknown organization', function (): void {
    $payload = json_encode(stripeWebhookEvent(), JSON_THROW_ON_ERROR);
    $organization = Organization::factory()->create();
    $provider = PaymentProvider::factory()->forOrganization($organization)->withWebhookSecret()->create();

    $this->call('POST', '/webhooks/stripe/00000000-0000-0000-0000-000000000000', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSign($payload, $provider->webhookSecret()),
    ], $payload)->assertBadRequest();
});

it('rejects a webhook when the code does not match a provider', function (): void {
    [$organization] = stripeWebhookOrganization();
    $payload = json_encode(stripeWebhookEvent(), JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/stripe/{$organization->id}?code=unknown", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSign($payload, 'whsec_anything'),
    ], $payload)->assertBadRequest();
});

// -- payment_intent.succeeded ---------------------------------------------------

it('marks the payment succeeded and the invoice payment_status followed', function (): void {
    [$organization, $provider] = stripeWebhookOrganization();
    [$customer, $invoice] = stripeWebhookInvoice($organization, $provider, ['total_amount_cents' => 1000, 'ready_for_payment_processing' => true]);

    Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'provider_payment_id' => 'pi_123',
        'status' => 'processing',
        'payable_payment_status' => 'processing',
        'amount_cents' => 1000,
    ]);

    $result = StripeService::updatePaymentStatus(
        organizationId: $organization->id,
        status: 'succeeded',
        stripePayment: new StripePayment(
            id: 'pi_123',
            status: 'succeeded',
            metadata: ['lago_customer_id' => $customer->id],
        ),
    );

    expect($result->success())->toBeTrue();

    $payment = Payment::query()->where('provider_payment_id', 'pi_123')->first();
    expect($payment->status)->toBe('succeeded')
        ->and($payment->payablePaymentStatus())->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('succeeded')
        ->and($invoice->total_paid_amount_cents)->toBe(1000)
        ->and($invoice->ready_for_payment_processing)->toBeFalse();
});

it('marks the payment failed with the last payment error code', function (): void {
    [$organization, $provider] = stripeWebhookOrganization();
    [$customer, $invoice] = stripeWebhookInvoice($organization, $provider, ['total_amount_cents' => 1000]);

    Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'provider_payment_id' => 'pi_fail',
        'status' => 'processing',
        'payable_payment_status' => 'processing',
        'amount_cents' => 1000,
    ]);

    StripeService::updatePaymentStatus(
        organizationId: $organization->id,
        status: 'failed',
        stripePayment: new StripePayment(
            id: 'pi_fail',
            status: 'requires_payment_method',
            metadata: ['lago_customer_id' => $customer->id],
            errorCode: 'card_declined',
        ),
    );

    $payment = Payment::query()->where('provider_payment_id', 'pi_fail')->first();
    expect($payment->status)->toBe('failed')
        ->and($payment->payablePaymentStatus())->toBe('failed')
        ->and($payment->error_code)->toBe('card_declined')
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('failed')
        ->and($invoice->ready_for_payment_processing)->toBeTrue();
});

it('ignores a settled event for an already succeeded invoice', function (): void {
    [$organization, $provider] = stripeWebhookOrganization();
    [$customer, $invoice] = stripeWebhookInvoice($organization, $provider, ['total_amount_cents' => 1000, 'total_paid_amount_cents' => 1000]);

    Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'provider_payment_id' => 'pi_dup',
        'status' => 'processing',
        'payable_payment_status' => 'processing',
        'amount_cents' => 1000,
    ]);

    // First settle.
    StripeService::updatePaymentStatus(
        organizationId: $organization->id,
        status: 'succeeded',
        stripePayment: new StripePayment(id: 'pi_dup', status: 'succeeded', metadata: []),
    );

    expect($invoice->refresh()->total_paid_amount_cents)->toBe(1000);
});

it('ignores events whose payment belongs to another organization', function (): void {
    [$organization, $provider] = stripeWebhookOrganization();
    $otherOrganization = Organization::factory()->create();
    $otherCustomer = Customer::factory()->forOrganization($otherOrganization)->create();
    $invoice = App\Models\Invoice::factory()->for($otherCustomer, 'customer')
        ->for($otherOrganization, 'organization')
        ->create(['total_amount_cents' => 1000]);

    Payment::factory()->forInvoice($invoice)->forCustomer($otherCustomer)->create([
        'payment_provider_id' => $provider->id,
        'provider_payment_id' => 'pi_other',
        'status' => 'processing',
        'payable_payment_status' => 'processing',
        'amount_cents' => 1000,
    ]);

    StripeService::updatePaymentStatus(
        organizationId: $organization->id,
        status: 'succeeded',
        stripePayment: new StripePayment(id: 'pi_other', status: 'succeeded', metadata: []),
    );

    expect(Payment::query()->where('provider_payment_id', 'pi_other')->first()->status)->toBe('processing')
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('pending')
        ->and($invoice->total_paid_amount_cents)->toBe(0);
});

it('swallows a not-found payment on a sandbox event and re-raises on live', function (): void {
    [$organization] = stripeWebhookOrganization();

    $eventJson = fn (bool $livemode): string => json_encode([
        'type' => 'payment_intent.succeeded',
        'livemode' => $livemode,
        'data' => ['object' => [
            'id' => 'pi_missing',
            'status' => 'succeeded',
            // one-time checkout event for a payment row that does not exist:
            // recreate-payment leg fails with invoice not_found.
            'metadata' => ['payment_type' => 'one-time', 'lago_invoice_id' => '00000000-0000-0000-0000-000000000001'],
        ]],
    ], JSON_THROW_ON_ERROR);

    // Sandbox: swallowed (Rails: logger.warn + empty result).
    $sandbox = App\Services\PaymentProviders\Stripe\HandleEventService::call(
        organization: $organization,
        eventJson: $eventJson(false),
    );
    expect($sandbox->success())->toBeTrue();

    // Live: the NotFoundFailure re-raises.
    App\Services\PaymentProviders\Stripe\HandleEventService::call(
        organization: $organization,
        eventJson: $eventJson(true),
    );
})->throws(App\Services\Failures\NotFoundFailure::class);
