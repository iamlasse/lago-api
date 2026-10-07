<?php

declare(strict_types=1);

use App\Models\Refund;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\CreditNote;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;
use App\Enums\CreditNoteRefundStatus;
use App\Services\CreditNotes\Refunds\AdyenService;
use App\Services\CreditNotes\Refunds\StripeService;
use App\Services\PaymentProviders\Adyen\AdyenError;
use App\Services\CreditNotes\Refunds\GocardlessService;
use App\Services\PaymentProviders\Gocardless\GoCardlessError;
use App\Services\PaymentProviders\Adyen\HandleEventService as AdyenHandleEventService;
use App\Services\PaymentProviders\Stripe\HandleEventService as StripeHandleEventService;
use App\Services\PaymentProviders\Gocardless\HandleEventService as GocardlessHandleEventService;

/**
 * Ports of the credit-note refund specs
 * (spec/services/credit_notes/refunds/{stripe,adyen,gocardless}_service_spec.rb)
 * plus the webhook wirings (Adyen REFUND / REFUND_FAILED, GoCardless
 * refunds events, Stripe charge.refund.updated).
 */
function refundOrganization(string $provider, array $secrets = [], array $settings = []): array
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
 * A refundable credit note: finalized, with a refund leg, its invoice paid
 * through a succeeded provider payment.
 */
function refundCreditNote(Organization $organization, PaymentProvider $providerModel, array $creditNoteAttributes = []): array
{
    $customer = App\Models\Customer::factory()->forOrganization($organization)->create();
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
        ->create([
            'total_amount_cents' => 1000,
            'currency' => 'EUR',
            'total_paid_amount_cents' => 1000,
            'payment_status' => 1, // succeeded
        ]);

    $payment = Payment::factory()->pending()->forInvoice($invoice)->forCustomer($customer)->create([
        'organization_id' => $organization->id,
        'payment_provider_id' => $providerModel->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'provider_payment_id' => 'provider_pay_1',
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'status' => 'succeeded',
        'payable_payment_status' => 'succeeded',
    ]);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->finalized()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'refund_amount_cents' => 400,
        'refund_amount_currency' => 'EUR',
        'credit_amount_currency' => 'EUR',
    ], $creditNoteAttributes));

    return [$customer, $invoice, $payment, $creditNote];
}

// -- StripeService ---------------------------------------------------------------

it('creates a stripe refund for a refundable credit note', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_123',
            'amount' => 400,
            'currency' => 'eur',
            'status' => 'succeeded',
            'payment_intent' => 'provider_pay_1',
        ]),
    ]);

    $result = StripeService::create($creditNote);

    expect($result->success())->toBeTrue();

    $refund = $result->refund;
    expect($refund)->toBeInstanceOf(Refund::class)
        ->and($refund->provider_refund_id)->toBe('re_123')
        ->and($refund->amount_cents)->toBe(400)
        ->and($refund->amount_currency)->toBe('EUR')
        ->and($refund->status)->toBe('succeeded')
        ->and($refund->reason)->toBe('credit_note')
        ->and($refund->payment_id)->not->toBeNull()
        ->and($refund->credit_note_id)->toBe($creditNote->id);

    $creditNote->refresh();
    expect($creditNote->refundStatusEnum())->toBe(CreditNoteRefundStatus::Succeeded)
        ->and($creditNote->refunded_at)->not->toBeNull();

    Http::assertSent(fn($request): bool => str_contains($request->url(), '/v1/refunds')
        && $request['payment_intent'] === 'provider_pay_1'
        && $request['amount'] === '400'
        && $request['reason'] === 'duplicate'
        && $request->header('Idempotency-Key')[0] === $creditNote->id
        && $request['metadata']['lago_credit_note_id'] === $creditNote->id);
});

it('does not create a refund when the credit note has no refund amount', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider, ['refund_amount_cents' => 0]);

    $result = StripeService::create($creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->refund)->toBeNull();

    expect(Refund::query()->count())->toBe(0);
});

it('does not create a refund when the invoice has no refundable payment', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [$customer, $invoice, $payment, $creditNote] = refundCreditNote($organization, $provider);

    $payment->update(['payable_payment_status' => 'failed']);

    $result = StripeService::create($creditNote->refresh());

    expect($result->success())->toBeTrue()
        ->and($result->refund)->toBeNull();
});

it('does not create a refund when the dispute was lost', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    $creditNote->invoice->update(['payment_dispute_lost_at' => now()]);

    $result = StripeService::create($creditNote->refresh());

    expect($result->refund)->toBeNull();
});

it('marks the refund failed on a stripe invalid request error', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'Charge has already been refunded', 'code' => 'charge_already_refunded'],
        ], 400),
    ]);

    $result = StripeService::create($creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('stripe_error');

    $creditNote->refresh();
    expect($creditNote->refundStatusEnum())->toBe(CreditNoteRefundStatus::Failed);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'credit_note.provider_refund_failure');
});

it('returns an empty result when the charge is not refundable', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'not refundable', 'code' => 'charge_not_refundable'],
        ], 400),
    ]);

    $result = StripeService::create($creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->refund)->toBeNull();

    $creditNote->refresh();
    expect($creditNote->refundStatusEnum())->toBe(CreditNoteRefundStatus::Failed);
});

it('updates the refund status from the webhook', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    $refund = Refund::factory()->create([
        'organization_id' => $organization->id,
        'credit_note_id' => $creditNote->id,
        'refundable_type' => 'CreditNote',
        'refundable_id' => $creditNote->id,
        'provider_refund_id' => 're_wh_1',
        'status' => 'pending',
    ]);

    $result = StripeService::updateStatus('re_wh_1', 'succeeded');

    expect($result->success())->toBeTrue()
        ->and($result->refund->refresh()->status)->toBe('succeeded');

    $creditNote->refresh();
    expect($creditNote->refundStatusEnum())->toBe(CreditNoteRefundStatus::Succeeded);
});

it('fails the service when the refund status is failed', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Refund::factory()->create([
        'organization_id' => $organization->id,
        'credit_note_id' => $creditNote->id,
        'refundable_type' => 'CreditNote',
        'refundable_id' => $creditNote->id,
        'provider_refund_id' => 're_wh_2',
        'status' => 'pending',
    ]);

    $result = StripeService::updateStatus('re_wh_2', 'failed');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('refund_failed');

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'credit_note.provider_refund_failure');
});

it('ignores a refund not initiated by lago', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    // No lago_invoice_id metadata — refund was not initiated by lago.
    $result = StripeService::updateStatus('re_unknown', 'succeeded');

    expect($result->success())->toBeTrue();

    // A known invoice id with no refund row fails with not_found.
    $result = StripeService::updateStatus('re_unknown', 'succeeded', ['lago_invoice_id' => $creditNote->invoice_id]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError() instanceof App\Services\Failures\NotFoundFailure)->toBeTrue();

    // An unknown invoice id is ignored too.
    $result = StripeService::updateStatus('re_unknown', 'succeeded', ['lago_invoice_id' => '00000000-0000-0000-0000-000000000000']);

    expect($result->success())->toBeTrue();
});

it('handles the stripe charge.refund.updated webhook event', function (): void {
    [$organization, $provider] = refundOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    $refund = Refund::factory()->create([
        'organization_id' => $organization->id,
        'credit_note_id' => $creditNote->id,
        'refundable_type' => 'CreditNote',
        'refundable_id' => $creditNote->id,
        'provider_refund_id' => 're_event_1',
        'status' => 'pending',
    ]);

    $result = (new StripeHandleEventService($organization, json_encode([
        'type' => 'charge.refund.updated',
        'livemode' => false,
        'data' => ['object' => ['id' => 're_event_1', 'status' => 'succeeded', 'metadata' => []]],
    ])))->execute();

    expect($result->success())->toBeTrue()
        ->and($refund->refresh()->status)->toBe('succeeded');
});

// -- AdyenService ----------------------------------------------------------------

it('creates an adyen refund through the checkout modifications api', function (): void {
    [$organization, $provider] = refundOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Http::fake([
        'checkout-test.adyen.com/*/payments/provider_pay_1/refunds' => Http::response([
            'pspReference' => 'refund_psp_1',
            'status' => 'received',
            'amount' => ['value' => 400, 'currency' => 'EUR'],
        ]),
    ]);

    $result = AdyenService::create($creditNote);

    expect($result->success())->toBeTrue();

    $refund = $result->refund;
    expect($refund->provider_refund_id)->toBe('refund_psp_1')
        ->and($refund->amount_cents)->toBe(400)
        ->and($refund->amount_currency)->toBe('EUR')
        ->and($refund->status)->toBe('pending');

    $creditNote->refresh();
    expect($creditNote->refundStatusEnum())->toBe(CreditNoteRefundStatus::Pending);

    Http::assertSent(fn($request): bool => $request['paymentPspReference'] === 'provider_pay_1'
        && $request['merchantAccount'] === 'LagoMerchant'
        && $request['amount']['value'] === 400);
});

it('re-raises and fails the credit note on an adyen error', function (): void {
    [$organization, $provider] = refundOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Http::fake([
        'checkout-test.adyen.com/*/payments/provider_pay_1/refunds' => Http::response([
            'status' => 422,
            'message' => 'Insufficient balance',
            'errorType' => 'validation',
        ], 422),
    ]);

    try {
        AdyenService::create($creditNote);

        $this->fail('Expected AdyenError to be raised');
    } catch (AdyenError $e) {
        expect($e->msg)->toBe('Insufficient balance');
    }

    $creditNote->refresh();
    expect($creditNote->refundStatusEnum())->toBe(CreditNoteRefundStatus::Failed);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'credit_note.provider_refund_failure');
});

it('handles the adyen REFUND and REFUND_FAILED webhook events', function (): void {
    [$organization, $provider] = refundOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    $refund = Refund::factory()->create([
        'organization_id' => $organization->id,
        'credit_note_id' => $creditNote->id,
        'refundable_type' => 'CreditNote',
        'refundable_id' => $creditNote->id,
        'provider_refund_id' => 'refund_psp_wh',
        'status' => 'pending',
    ]);

    $result = (new AdyenHandleEventService($organization, json_encode([
        'eventCode' => 'REFUND',
        'success' => 'true',
        'pspReference' => 'refund_psp_wh',
    ])))->execute();

    expect($result->success())->toBeTrue()
        ->and($refund->refresh()->status)->toBe('succeeded')
        ->and($creditNote->refresh()->refundStatusEnum())->toBe(CreditNoteRefundStatus::Succeeded);

    $result = (new AdyenHandleEventService($organization, json_encode([
        'eventCode' => 'REFUND_FAILED',
        'success' => 'true',
        'pspReference' => 'refund_psp_wh',
    ])))->execute();

    // Already succeeded — the second event is a no-op (Rails: return result).
    expect($result->success())->toBeTrue();
});

// -- GocardlessService -----------------------------------------------------------

it('creates a gocardless refund', function (): void {
    [$organization, $provider] = refundOrganization('gocardless', ['access_token' => 'gc_token']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Http::fake([
        'api-sandbox.gocardless.com/refunds' => Http::response([
            'refunds' => [
                'id' => 'refund_gc_1',
                'amount' => 400,
                'currency' => 'EUR',
                'status' => 'created',
            ],
        ]),
    ]);

    $result = GocardlessService::create($creditNote);

    expect($result->success())->toBeTrue();

    $refund = $result->refund;
    expect($refund->provider_refund_id)->toBe('refund_gc_1')
        ->and($refund->status)->toBe('created');

    $creditNote->refresh();
    expect($creditNote->refundStatusEnum())->toBe(CreditNoteRefundStatus::Pending);

    Http::assertSent(fn($request): bool => $request['params']['amount'] === 400
        && $request['params']['total_amount_confirmation'] === 400
        && $request['params']['links']['payment'] === 'provider_pay_1'
        && $request->header('Idempotency-Key')[0] === $request['params']['metadata']['lago_credit_note_id']);
});

it('maps the gocardless provider status onto the credit note status', function (): void {
    expect(GocardlessService::creditNoteStatus('created'))->toBe('pending')
        ->and(GocardlessService::creditNoteStatus('refund_settled'))->toBe('pending')
        ->and(GocardlessService::creditNoteStatus('paid'))->toBe('succeeded')
        ->and(GocardlessService::creditNoteStatus('funds_returned'))->toBe('failed')
        ->and(GocardlessService::creditNoteStatus('failed'))->toBe('failed');
});

it('re-raises a gocardless error', function (): void {
    [$organization, $provider] = refundOrganization('gocardless', ['access_token' => 'gc_token']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Http::fake([
        'api-sandbox.gocardless.com/refunds' => Http::response([
            'error' => ['message' => 'Insufficient funds', 'error_type' => 'invalid_api_usage'],
        ], 400),
    ]);

    try {
        GocardlessService::create($creditNote);

        $this->fail('Expected GoCardlessError to be raised');
    } catch (GoCardlessError $e) {
        expect($e->getMessage())->toBe('Insufficient funds');
    }

    expect($creditNote->refresh()->refundStatusEnum())->toBe(CreditNoteRefundStatus::Failed);
});

it('swallows a gocardless validation error', function (): void {
    [$organization, $provider] = refundOrganization('gocardless', ['access_token' => 'gc_token']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Http::fake([
        'api-sandbox.gocardless.com/refunds' => Http::response([
            'error' => ['message' => 'Invalid metadata', 'error_type' => 'validation_error'],
        ], 400),
    ]);

    $result = GocardlessService::create($creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->refund)->toBeNull();
});

it('fails the service when the gocardless refund fails', function (): void {
    [$organization, $provider] = refundOrganization('gocardless', ['access_token' => 'gc_token']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    Refund::factory()->create([
        'organization_id' => $organization->id,
        'credit_note_id' => $creditNote->id,
        'refundable_type' => 'CreditNote',
        'refundable_id' => $creditNote->id,
        'provider_refund_id' => 'refund_gc_wh',
        'status' => 'submitted',
    ]);

    $result = GocardlessService::updateStatus('refund_gc_wh', 'funds_returned', ['lago_invoice_id' => $creditNote->invoice_id]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('refund_failed')
        ->and($creditNote->refresh()->refundStatusEnum())->toBe(CreditNoteRefundStatus::Failed);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'credit_note.provider_refund_failure');
});

it('handles the gocardless refunds webhook events', function (): void {
    [$organization, $provider] = refundOrganization('gocardless', ['access_token' => 'gc_token']);
    [, , , $creditNote] = refundCreditNote($organization, $provider);

    $refund = Refund::factory()->create([
        'organization_id' => $organization->id,
        'credit_note_id' => $creditNote->id,
        'refundable_type' => 'CreditNote',
        'refundable_id' => $creditNote->id,
        'provider_refund_id' => 'refund_gc_event',
        'status' => 'created',
    ]);

    $service = new GocardlessHandleEventService($provider, json_encode([
        'resource_type' => 'refunds',
        'action' => 'paid',
        'links' => ['refund' => 'refund_gc_event'],
        'metadata' => [],
    ]));

    $result = $service->execute();

    expect($result->success())->toBeTrue()
        ->and($refund->refresh()->status)->toBe('paid')
        ->and($creditNote->refresh()->refundStatusEnum())->toBe(CreditNoteRefundStatus::Succeeded);
});

it('refunds the payment request payment of a paid invoice', function (): void {
    // Rails: "with a payment request for an invoice" — the refundable
    // payment is the payment request settlement, not the invoice's own.
    [$organization, $provider] = refundOrganization('gocardless', ['access_token' => 'gc_token']);
    [$customer, $invoice, $payment, $creditNote] = refundCreditNote($organization, $provider);

    $payment->update(['payable_payment_status' => 'failed']);

    $paymentRequest = App\Models\PaymentRequest::factory()->for($organization, 'organization')->create([
        'customer_id' => $customer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'payment_status' => 1, // succeeded
    ]);
    $invoice->paymentRequests()->attach($paymentRequest->id, ['organization_id' => $organization->id, 'created_at' => now(), 'updated_at' => now()]);
    $payment->update(['payable_type' => 'PaymentRequest', 'payable_id' => $paymentRequest->id, 'payable_payment_status' => 'succeeded']);

    Http::fake([
        'api-sandbox.gocardless.com/refunds' => Http::response([
            'refunds' => ['id' => 'refund_gc_pr', 'amount' => 400, 'currency' => 'EUR', 'status' => 'created'],
        ]),
    ]);

    $result = GocardlessService::create($creditNote->refresh());

    expect($result->success())->toBeTrue()
        ->and($result->refund->payment_id)->toBe($payment->id);
});
