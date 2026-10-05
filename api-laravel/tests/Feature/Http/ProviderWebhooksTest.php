<?php

declare(strict_types=1);

uses()->group('ledger:webhooks:providers');

use App\Jobs\PaymentProviders\AdyenHandleEventJob;
use App\Jobs\PaymentProviders\CashfreeHandleEventJob;
use App\Jobs\PaymentProviders\FlutterwaveHandleEventJob;
use App\Jobs\PaymentProviders\GocardlessHandleEventJob;
use App\Jobs\PaymentProviders\MoneyhashHandleEventJob;
use App\Models\Customer;
use App\Models\InboundWebhook;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Services\Invoices\Payments\CashfreeService;
use App\Services\PaymentProviders\Adyen\HandleEventService as AdyenHandleEvent;
use App\Services\PaymentProviders\Flutterwave\Webhooks\ChargeCompletedService;
use App\Services\PaymentProviders\Gocardless\HandleEventService as GocardlessHandleEvent;
use App\Services\PaymentProviders\Moneyhash\HandleEventService;
use App\Values\CashfreePayment;
use Illuminate\Support\Facades\Http;

/**
 * Port of Rails' spec/requests/webhooks_controller_spec.rb (the non-stripe
 * providers) and the provider handle_*_service specs — the signature
 * verification per provider (Adyen notification-item HMAC with the hex
 * packed key, GoCardless hex HMAC of the raw body, Cashfree base64 HMAC of
 * "{timestamp}{body}", Flutterwave verif-hash equality, Moneyhash t/v3
 * HMAC of base64(payload)+t) and the payment/invoice status transitions.
 */
function providerOrganization(string $provider, array $secrets = [], array $settings = []): array
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

function providerInvoice(Organization $organization, PaymentProvider $providerModel, array $attributes = []): array
{
    $customer = Customer::factory()->forOrganization($organization)->create();
    $customer->update(['payment_provider' => $providerModel->code, 'payment_provider_code' => $providerModel->code]);
    $customer->paymentProviderCustomers()->create([
        'payment_provider_id' => $providerModel->id,
        'type' => 'PaymentProviderCustomers::'.ucfirst($providerModel->paymentType()).'Customer',
        'provider_customer_id' => 'cus_123',
        'organization_id' => $organization->id,
        'settings' => [],
    ]);

    $invoice = Invoice::factory()->for($customer, 'customer')
        ->for($organization, 'organization')
        ->create(array_merge(['total_amount_cents' => 1000], $attributes));

    return [$customer, $invoice];
}

function adyenNotification(array $itemOverrides = []): array
{
    return array_merge([
        'pspReference' => 'psp_123',
        'originalReference' => '',
        'merchantAccountCode' => 'LagoMerchant',
        'merchantReference' => 'ref_123',
        'amount' => ['value' => 1000, 'currency' => 'EUR'],
        'eventCode' => 'AUTHORISATION',
        'success' => 'true',
        'additionalData' => [
            'metadata.payment_type' => 'one-time',
            'metadata.lago_invoice_id' => '',
        ],
    ], $itemOverrides);
}

function adyenSign(array $item, string $hexKey): string
{
    $parts = [
        (string) ($item['pspReference'] ?? ''),
        (string) ($item['originalReference'] ?? ''),
        (string) ($item['merchantAccountCode'] ?? ''),
        (string) ($item['merchantReference'] ?? ''),
        (string) ($item['amount']['value'] ?? ''),
        (string) ($item['amount']['currency'] ?? ''),
        (string) ($item['eventCode'] ?? ''),
        (string) ($item['success'] ?? ''),
    ];

    return base64_encode(hash_hmac('sha256', implode(':', $parts), hex2bin($hexKey), true));
}

function adyenWebhookOrganization(): array
{
    return providerOrganization('adyen', ['api_key' => 'adyen_key', 'hmac_key' => str_repeat('ab', 16)], ['merchant_account' => 'LagoMerchant']);
}

// -- Adyen ----------------------------------------------------------------------

it('accepts an adyen webhook with a valid notification HMAC and queues the event', function (): void {
    [$organization, $provider] = adyenWebhookOrganization();
    $item = adyenNotification(['additionalData' => [
        'metadata.payment_type' => 'one-time',
        'hmacSignature' => '',
    ]]);
    $item['additionalData']['hmacSignature'] = adyenSign($item, (string) $provider->hmacKey());

    $this->postJson("/webhooks/adyen/{$organization->id}?code={$provider->code}", [
        'notificationItems' => [['NotificationRequestItem' => $item]],
    ])->assertOk();

    Queue::assertPushed(AdyenHandleEventJob::class);
});

it('rejects an adyen webhook with an invalid HMAC', function (): void {
    [$organization, $provider] = adyenWebhookOrganization();
    $item = adyenNotification(['additionalData' => ['hmacSignature' => 'invalid=signature']]);

    $this->postJson("/webhooks/adyen/{$organization->id}?code={$provider->code}", [
        'notificationItems' => [['NotificationRequestItem' => $item]],
    ])->assertBadRequest();

    Queue::assertNotPushed(AdyenHandleEventJob::class);
});

it('moves the one-time payment on an adyen AUTHORISATION event', function (): void {
    [$organization, $provider] = adyenWebhookOrganization();
    [$customer, $invoice] = providerInvoice($organization, $provider, ['total_amount_cents' => 1000, 'ready_for_payment_processing' => true]);

    // The one-time (hosted checkout) AUTHORISATION recreates the payment
    // from the event metadata — no payment row exists yet.
    $item = adyenNotification(['additionalData' => [
        'metadata.payment_type' => 'one-time',
        'metadata.lago_invoice_id' => $invoice->id,
    ]]);

    $result = AdyenHandleEvent::call(organization: $organization, eventJson: json_encode($item, JSON_THROW_ON_ERROR));

    expect($result->success())->toBeTrue();

    $payment = Payment::query()->where('provider_payment_id', 'psp_123')->first();
    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe('succeeded')
        ->and($payment->payablePaymentStatus())->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('succeeded');
});

it('services-fails an invalid adyen event code', function (): void {
    [$organization, $provider] = adyenWebhookOrganization();

    $result = AdyenHandleEvent::call(
        organization: $organization,
        eventJson: json_encode(adyenNotification(['eventCode' => 'NOT_A_REAL_EVENT']), JSON_THROW_ON_ERROR),
    );

    expect($result->failure())->toBeTrue();
});

// -- GoCardless -----------------------------------------------------------------

it('accepts a gocardless webhook with a valid signature and queues the events', function (): void {
    [$organization, $provider] = providerOrganization('gocardless', ['access_token' => 'token'], ['webhook_secret' => 'gc_secret']);
    $body = json_encode(['events' => [['resource_type' => 'payments', 'action' => 'paid_out', 'links' => ['payment' => 'pm_1']]]], JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/gocardless/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, 'gc_secret'),
    ], $body)->assertOk();

    Queue::assertPushed(GocardlessHandleEventJob::class);
});

it('rejects a gocardless webhook with an invalid signature', function (): void {
    [$organization, $provider] = providerOrganization('gocardless', ['access_token' => 'token'], ['webhook_secret' => 'gc_secret']);
    $body = json_encode(['events' => []], JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/gocardless/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_SIGNATURE' => 'not-the-signature',
    ], $body)->assertBadRequest();

    Queue::assertNotPushed(GocardlessHandleEventJob::class);
});

it('marks the gocardless payment paid_out on the webhook action', function (): void {
    [$organization, $provider] = providerOrganization('gocardless', ['access_token' => 'token'], ['webhook_secret' => 'gc_secret']);
    [$customer, $invoice] = providerInvoice($organization, $provider, ['total_amount_cents' => 1000, 'ready_for_payment_processing' => true]);

    Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'provider_payment_id' => 'gc_pay_1',
        'status' => 'submitted',
        'payable_payment_status' => 'processing',
        'amount_cents' => 1000,
    ]);

    $event = ['resource_type' => 'payments', 'action' => 'paid_out', 'links' => ['payment' => 'gc_pay_1']];

    $result = GocardlessHandleEvent::call(
        paymentProvider: $provider,
        eventJson: json_encode($event, JSON_THROW_ON_ERROR),
    );

    expect($result->success())->toBeTrue();

    $payment = Payment::query()->where('provider_payment_id', 'gc_pay_1')->first();
    expect($payment->status)->toBe('paid_out')
        ->and($payment->payablePaymentStatus())->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('succeeded');
});

it('swallows a gocardless event for an unknown payment', function (): void {
    [$organization, $provider] = providerOrganization('gocardless', ['access_token' => 'token'], ['webhook_secret' => 'gc_secret']);

    $event = ['resource_type' => 'payments', 'action' => 'failed', 'links' => ['payment' => 'missing']];

    $result = GocardlessHandleEvent::call(
        paymentProvider: $provider,
        eventJson: json_encode($event, JSON_THROW_ON_ERROR),
    );

    // Rails: rescue BaseService::NotFoundFailure -> warn + fresh success.
    expect($result->success())->toBeTrue();
});

// -- Cashfree -------------------------------------------------------------------

it('accepts a cashfree webhook with a valid signature and queues the event', function (): void {
    [$organization, $provider] = providerOrganization('cashfree', ['client_id' => 'cid', 'client_secret' => 'csecret']);
    $body = json_encode(['type' => 'PAYMENT_LINK_EVENT'], JSON_THROW_ON_ERROR);
    $timestamp = '1700000000';
    $signature = base64_encode(hash_hmac('sha256', $timestamp.$body, 'csecret', true));

    $this->call('POST', "/webhooks/cashfree/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CASHFREE_TIMESTAMP' => $timestamp,
        'HTTP_X_CASHFREE_SIGNATURE' => $signature,
    ], $body)->assertOk();

    Queue::assertPushed(CashfreeHandleEventJob::class);
});

it('rejects a cashfree webhook with an invalid signature', function (): void {
    [$organization, $provider] = providerOrganization('cashfree', ['client_id' => 'cid', 'client_secret' => 'csecret']);
    $body = json_encode(['type' => 'PAYMENT_LINK_EVENT'], JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/cashfree/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CASHFREE_TIMESTAMP' => '1700000000',
        'HTTP_X_CASHFREE_SIGNATURE' => 'bad-signature',
    ], $body)->assertBadRequest();

    Queue::assertNotPushed(CashfreeHandleEventJob::class);
});

it('creates the payment and settles the invoice on a cashfree PAID link event', function (): void {
    [$organization, $provider] = providerOrganization('cashfree', ['client_id' => 'cid', 'client_secret' => 'csecret']);
    [$customer, $invoice] = providerInvoice($organization, $provider, ['total_amount_cents' => 1000, 'ready_for_payment_processing' => true]);

    $event = [
        'type' => 'PAYMENT_LINK_EVENT',
        'data' => [
            'link_status' => 'PAID',
            'link_amount_paid' => 10.0,
            'link_currency' => 'USD',
            'link_notes' => [
                'payment_type' => 'one-time',
                'lago_invoice_id' => $invoice->id,
                'lago_payable_type' => 'Invoice',
            ],
        ],
    ];

    $result = CashfreeService::updatePaymentStatus(
        organizationId: $organization->id,
        status: 'PAID',
        cashfreePayment: new CashfreePayment(id: $invoice->id, status: 'PAID', metadata: $event['data']['link_notes']),
        amountCents: 1000,
    );

    expect($result->success())->toBeTrue();

    $payment = Payment::query()->where('provider_payment_id', $invoice->id)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe('PAID')
        ->and($payment->payablePaymentStatus())->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('succeeded');
});

// -- Flutterwave ----------------------------------------------------------------

it('accepts a flutterwave webhook with the matching verif-hash and queues the event', function (): void {
    [$organization, $provider] = providerOrganization('flutterwave', ['secret_key' => 'sk', 'webhook_secret' => 'flw_hash']);
    $body = json_encode(['event' => 'charge.completed'], JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/flutterwave/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_VERIF_HASH' => 'flw_hash',
    ], $body)->assertOk();

    Queue::assertPushed(FlutterwaveHandleEventJob::class);
});

it('rejects a flutterwave webhook with a wrong verif-hash', function (): void {
    [$organization, $provider] = providerOrganization('flutterwave', ['secret_key' => 'sk', 'webhook_secret' => 'flw_hash']);
    $body = json_encode(['event' => 'charge.completed'], JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/flutterwave/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_VERIF_HASH' => 'wrong-hash',
    ], $body)->assertBadRequest();

    Queue::assertNotPushed(FlutterwaveHandleEventJob::class);
});

it('verifies the flutterwave transaction and settles the invoice on charge.completed', function (): void {
    [$organization, $provider] = providerOrganization('flutterwave', ['secret_key' => 'sk', 'webhook_secret' => 'flw_hash']);
    [$customer, $invoice] = providerInvoice($organization, $provider, ['total_amount_cents' => 1000, 'ready_for_payment_processing' => true]);

    Http::fake([
        '*/transactions/tx_9/verify' => Http::response([
            'status' => 'success',
            'data' => [
                'id' => 9001,
                'status' => 'successful',
                'amount' => 10.0,
                'currency' => 'USD',
                'tx_ref' => 'ref_9',
            ],
        ]),
    ]);

    $event = [
        'event' => 'charge.completed',
        'data' => [
            'id' => 'tx_9',
            'status' => 'successful',
            'tx_ref' => $invoice->id,
            'meta' => [
                'payment_type' => 'one-time',
                'lago_invoice_id' => $invoice->id,
                'lago_payable_type' => 'Invoice',
            ],
        ],
    ];

    $result = ChargeCompletedService::call(
        organizationId: $organization->id,
        eventJson: json_encode($event, JSON_THROW_ON_ERROR),
    );

    expect($result->success())->toBeTrue();

    $payment = Payment::query()->where('provider_payment_id', $invoice->id)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe('successful')
        ->and($payment->payablePaymentStatus())->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('succeeded');
});

// -- Moneyhash ------------------------------------------------------------------

it('accepts a moneyhash webhook with a valid t/v3 signature and stores the payload', function (): void {
    [$organization, $provider] = providerOrganization('moneyhash', ['api_key' => 'mh_key', 'signature_key' => 'mh_sig_key'], ['flow_id' => 'flow']);

    $payload = ['type' => 'intent.processed', 'data' => ['intent_id' => 'mh_int_1']];
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = '1700000000';
    $toSign = base64_encode($payloadJson).$timestamp;
    $signature = 't='.$timestamp.',v3='.hash_hmac('sha256', $toSign, 'mh_sig_key');

    $this->call('POST', "/webhooks/moneyhash/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_MONEYHASH_SIGNATURE' => $signature,
    ], $payloadJson)->assertOk();

    expect(InboundWebhook::query()->where('source', 'moneyhash')->exists())->toBeTrue();

    Queue::assertPushed(MoneyhashHandleEventJob::class);
});

it('rejects a moneyhash webhook with an invalid signature', function (): void {
    [$organization, $provider] = providerOrganization('moneyhash', ['api_key' => 'mh_key', 'signature_key' => 'mh_sig_key'], ['flow_id' => 'flow']);

    $payloadJson = json_encode(['type' => 'intent.processed'], JSON_THROW_ON_ERROR);

    $this->call('POST', "/webhooks/moneyhash/{$organization->id}?code={$provider->code}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_MONEYHASH_SIGNATURE' => 't=1700000000,v3=deadbeef',
    ], $payloadJson)->assertBadRequest();

    Queue::assertNotPushed(MoneyhashHandleEventJob::class);
});

it('moves the moneyhash payment on an intent.processed event', function (): void {
    [$organization, $provider] = providerOrganization('moneyhash', ['api_key' => 'mh_key', 'signature_key' => 'mh_sig_key'], ['flow_id' => 'flow']);
    [$customer, $invoice] = providerInvoice($organization, $provider, ['total_amount_cents' => 1000, 'ready_for_payment_processing' => true]);

    Payment::factory()->forInvoice($invoice)->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
        'provider_payment_id' => 'mh_int_1',
        'status' => 'PENDING',
        'payable_payment_status' => 'pending',
        'amount_cents' => 1000,
    ]);

    $event = [
        'type' => 'intent.processed',
        'data' => [
            'intent_id' => 'mh_int_1',
            'intent' => [
                'amount' => 10.0,
                'amount_currency' => 'USD',
                'custom_fields' => ['lago_payable_type' => 'Invoice'],
            ],
        ],
    ];

    $result = HandleEventService::call(
        organization: $organization,
        eventJson: json_encode($event, JSON_THROW_ON_ERROR),
    );

    expect($result->success())->toBeTrue();

    $payment = Payment::query()->where('provider_payment_id', 'mh_int_1')->first();
    expect($payment->status)->toBe('succeeded')
        ->and($payment->payablePaymentStatus())->toBe('succeeded')
        ->and($invoice->refresh()->paymentStatusEnum()->label())->toBe('succeeded');
});

it('services-fails an unknown moneyhash event code', function (): void {
    [$organization, $provider] = providerOrganization('moneyhash', ['api_key' => 'mh_key', 'signature_key' => 'mh_sig_key'], ['flow_id' => 'flow']);

    $result = HandleEventService::call(
        organization: $organization,
        eventJson: json_encode(['type' => 'totally.unknown'], JSON_THROW_ON_ERROR),
    );

    expect($result->failure())->toBeTrue();
});
