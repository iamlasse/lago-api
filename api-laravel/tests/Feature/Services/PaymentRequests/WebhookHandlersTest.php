<?php

declare(strict_types=1);

use App\Models\Refund;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;
use App\Enums\CreditNoteRefundStatus;
use App\Services\Failures\ServiceFailure;
use App\Services\PaymentRequests\UpdateService;
use App\Services\CreditNotes\Refunds\StripeService;
use App\Services\PaymentRequests\Payments\AdyenService;
use App\Services\PaymentRequests\Payments\DeliverErrorWebhookService;
use App\Services\PaymentRequests\CreateService as RequestCreateService;
use App\Services\PaymentRequests\Payments\BaseService as PaymentsBaseService;
use App\Services\PaymentRequests\Payments\CreateService as PaymentsCreateService;

/**
 * Direct evidence for the webhook-handler rows the PaymentRequests /
 * CreditNotes slices delivered inline rather than as standalone builder
 * services:
 *  - PaymentRequests::Payments::DeliverErrorWebhookService (a real ported
 *    class — spec/services/payment_requests/payments/deliver_error_webhook_service_spec.rb);
 *  - the Updatable concern, folded into PaymentsBaseService
 *    (update_invoices_paid_amount_cents);
 *  - Webhooks::PaymentRequests::{Created,PaymentStatusUpdated} — folded into
 *    the PaymentRequests Create/Update services' SendWebhookJob legs;
 *  - Webhooks::PaymentProviders::PaymentRequestPaymentFailureService —
 *    folded into DeliverErrorWebhookService + the provider failure arms;
 *  - Webhooks::CreditNotes::PaymentProviderRefundFailureService — folded
 *    into CreditNotes\Refunds\BaseService::deliverErrorWebhook;
 *  - the `raise if e.result.reraise` leg of
 *    PaymentRequests::Payments::CreateService (create_service.rb).
 */
function prwhOrganization(string $provider, array $secrets = [], array $settings = []): array
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
function prwhPayable(Organization $organization, PaymentProvider $providerModel): array
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
            'total_paid_amount_cents' => 200,
            'currency' => 'EUR',
            'ready_for_payment_processing' => true,
            'payment_overdue' => true,
        ]);

    $payable = PaymentRequest::factory()->forCustomer($customer)->create([
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
    ]);
    $payable->invoices()->attach($invoice->id, [
        'organization_id' => $organization->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$customer, $invoice, $providerCustomer, $payable];
}

/**
 * A refundable credit note: finalized, with a refund leg, its invoice paid
 * through a succeeded provider payment.
 */
function prwhRefundCreditNote(Organization $organization, PaymentProvider $providerModel): array
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

    $creditNote = App\Models\CreditNote::factory()->forInvoice($invoice)->finalized()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'refund_amount_cents' => 400,
        'refund_amount_currency' => 'EUR',
        'credit_amount_currency' => 'EUR',
    ]);

    return [$customer, $invoice, $payment, $creditNote];
}

// -- PaymentRequests::Payments::DeliverErrorWebhookService ------------------------

it('enqueues the payment request payment failure webhook', function (): void {
    [$organization] = prwhOrganization('stripe');
    $customer = Customer::factory()->forOrganization($organization)->create();
    $payable = PaymentRequest::factory()->forCustomer($customer)->create();

    $params = [
        'provider_customer_id' => 'customer',
        'provider_error' => [
            'error_message' => 'message',
            'error_code' => 'code',
        ],
    ];

    DeliverErrorWebhookService::callAsync($payable, $params);

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_request.payment_failure'
        && $job->object->is($payable)
        && $job->options === $params);
});

it('skips the payment failure webhook when the organization has no endpoints', function (): void {
    $organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $payable = PaymentRequest::factory()->forCustomer($customer)->create();

    DeliverErrorWebhookService::callAsync($payable, [
        'provider_customer_id' => 'customer',
        'provider_error' => ['error_message' => 'message', 'error_code' => 'code'],
    ]);

    Queue::assertNothingPushed();
});

// -- PaymentRequests::Payments::Updatable (folded into BaseService) ---------------

it('absorbs the remaining due amount into each invoice on success', function (): void {
    [$organization, $provider] = prwhOrganization('stripe');
    [, $invoice, , $payable] = prwhPayable($organization, $provider);

    // paid 200 of 1000 -> the remaining 800 is absorbed on success.
    $method = new ReflectionMethod(PaymentsBaseService::class, 'updateInvoicesPaidAmountCents');
    $method->invoke(null, $payable, 'succeeded');

    expect($invoice->refresh()->total_paid_amount_cents)->toBe(1000);
});

it('leaves the invoice paid amounts alone on a non-succeeded status', function (): void {
    [$organization, $provider] = prwhOrganization('stripe');
    [, $invoice, , $payable] = prwhPayable($organization, $provider);

    $method = new ReflectionMethod(PaymentsBaseService::class, 'updateInvoicesPaidAmountCents');

    $method->invoke(null, $payable, 'failed');
    expect($invoice->refresh()->total_paid_amount_cents)->toBe(200);

    $method->invoke(null, null, 'succeeded');
    expect($invoice->refresh()->total_paid_amount_cents)->toBe(200);
});

it('updates the invoices paid amounts through the provider success leg', function (): void {
    [$organization, $provider] = prwhOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, $invoice, $providerCustomer, $payable] = prwhPayable($organization, $provider);

    Payment::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'PaymentRequest',
        'payable_id' => $payable->id,
        'payment_provider_id' => $provider->id,
        'payment_provider_customer_id' => $providerCustomer->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'provider_payment_id' => 'psp_pr_paid',
        'status' => 'pending',
        'payable_payment_status' => 'pending',
    ]);

    $result = AdyenService::updatePaymentStatus(
        providerPaymentId: 'psp_pr_paid',
        status: 'Authorised',
    );

    expect($result->success())->toBeTrue()
        ->and($payable->refresh()->paymentStatus())->toBe('succeeded')
        // The Updatable concern leg: 200 paid + 800 remaining due.
        ->and($invoice->refresh()->total_paid_amount_cents)->toBe(1000);
});

// -- Webhooks::PaymentRequests::CreatedService (folded into the create flow) ------

it('delivers the payment request created webhook from the creation flow', function (): void {
    [$organization, $provider] = prwhOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [$customer, $invoice] = prwhPayable($organization, $provider);

    config(['lago.license' => 'premium-license-token']);

    $result = (new RequestCreateService($organization, [
        'external_customer_id' => $customer->external_id,
        'lago_invoice_ids' => [$invoice->id],
    ]))->execute();

    expect($result->success())->toBeTrue();

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_request.created'
        && $job->object->is($result->payment_request));
});

it('does not deliver the created webhook when the flow is forbidden', function (): void {
    [$organization, $provider] = prwhOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [$customer, $invoice] = prwhPayable($organization, $provider);

    // No premium license -> forbidden before anything is created.
    $result = (new RequestCreateService($organization, [
        'external_customer_id' => $customer->external_id,
        'lago_invoice_ids' => [$invoice->id],
    ]))->execute();

    expect($result->failure())->toBeTrue();

    Queue::assertNotPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_request.created');
});

// -- Webhooks::PaymentRequests::PaymentStatusUpdatedService (folded into UpdateService)

it('delivers the payment status updated webhook when the status changes', function (): void {
    [$organization] = prwhOrganization('stripe');
    $customer = Customer::factory()->forOrganization($organization)->create();
    $payable = PaymentRequest::factory()->forCustomer($customer)->create();

    $result = UpdateService::call(payable: $payable, params: [
        'payment_status' => 'succeeded',
        'ready_for_payment_processing' => false,
    ], webhookNotification: true);

    expect($result->success())->toBeTrue();

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_request.payment_status_updated'
        && $job->object->is($payable));
});

it('does not deliver the payment status updated webhook without a change or notification', function (): void {
    [$organization] = prwhOrganization('stripe');
    $customer = Customer::factory()->forOrganization($organization)->create();
    $payable = PaymentRequest::factory()->forCustomer($customer)->create();

    // Same status -> not dirty -> no webhook even with notification on.
    UpdateService::call(payable: $payable, params: ['payment_status' => 'pending'], webhookNotification: true);
    Queue::assertNotPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_request.payment_status_updated');

    // A status change with the notification off stays silent.
    UpdateService::call(payable: $payable, params: ['payment_status' => 'succeeded'], webhookNotification: false);
    Queue::assertNotPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_request.payment_status_updated');
});

// -- Webhooks::PaymentProviders::PaymentRequestPaymentFailureService (fold) --------

it('delivers the payment request payment failure webhook on a provider failure', function (): void {
    [$organization, $provider] = prwhOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [$customer, $invoice, $providerCustomer, $payable] = prwhPayable($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'message' => 'Your card was declined.',
            ],
        ], 402),
    ]);

    $result = (new PaymentsCreateService(payable: $payable))->execute();

    // Rails' rescue returns the bare success result with the failed
    // payment attached — the ServiceFailure is handled, not propagated.
    expect($result->success())->toBeTrue()
        ->and($result->payment->payablePaymentStatus())->toBe('failed')
        ->and($payable->refresh()->paymentStatus())->toBe('failed');

    // Rails: Webhooks::PaymentProviders::PaymentRequestPaymentFailureService
    // serializes provider_error + provider_customer_id onto the payment
    // request — the folded enqueue carries the same options.
    Queue::assertPushed(SendWebhookJob::class, fn(SendWebhookJob $job): bool => $job->webhookType === 'payment_request.payment_failure'
        && $job->object->is($payable)
        && $job->options['provider_customer_id'] === 'prov_cus_1'
        && $job->options['provider_error'] === [
            'message' => 'Your card was declined.',
            'error_code' => 'card_declined',
        ]);
});

// -- `raise if e.result.reraise` (PaymentRequests::Payments::CreateService) --------

it('re-raises a provider failure that carries reraise', function (): void {
    [$organization, $provider] = prwhOrganization('adyen', ['api_key' => 'adyen_key'], ['merchant_account' => 'LagoMerchant']);
    [$customer, $invoice, $providerCustomer, $payable] = prwhPayable($organization, $provider);

    Http::fake([
        'checkout-test.adyen.com/*/paymentMethods' => Http::response(['storedPaymentMethods' => []]),
        'checkout-test.adyen.com/*/payments' => Http::response([
            'status' => 500,
            'message' => 'Internal error',
            'errorType' => 'internal_error',
        ], 500),
    ]);

    expect(fn () => (new PaymentsCreateService(payable: $payable))->execute())
        ->toThrow(ServiceFailure::class);

    // The failure legs still ran before the raise: the webhook went out and
    // the payment request follows the failed payment.
    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'payment_request.payment_failure');
    expect($payable->refresh()->paymentStatus())->toBe('failed');
});

// -- Webhooks::CreditNotes::PaymentProviderRefundFailureService (fold) -------------

it('delivers the provider refund failure webhook on a stripe refund error', function (): void {
    [$organization, $provider] = prwhOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , , $creditNote] = prwhRefundCreditNote($organization, $provider);

    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'Charge has already been refunded', 'code' => 'charge_already_refunded'],
        ], 400),
    ]);

    $result = StripeService::create($creditNote);

    expect($result->failure())->toBeTrue()
        ->and($creditNote->refresh()->refundStatusEnum())->toBe(CreditNoteRefundStatus::Failed);

    // Rails: Webhooks::CreditNotes::PaymentProviderRefundFailureService
    // serializes provider_error + provider_customer_id onto the credit note.
    Queue::assertPushed(SendWebhookJob::class, fn(SendWebhookJob $job): bool => $job->webhookType === 'credit_note.provider_refund_failure'
        && $job->object->is($creditNote)
        && $job->options['provider_customer_id'] === 'prov_cus_1'
        && $job->options['provider_error'] === [
            'message' => 'Charge has already been refunded',
            'error_code' => 'charge_already_refunded',
        ]);
});

it('delivers the provider refund failure webhook when the refund fails on the webhook', function (): void {
    [$organization, $provider] = prwhOrganization('stripe', ['secret_key' => 'sk_test_1']);
    [, , $payment, $creditNote] = prwhRefundCreditNote($organization, $provider);

    Refund::factory()->create([
        'organization_id' => $organization->id,
        'credit_note_id' => $creditNote->id,
        'payment_id' => $payment->id,
        'refundable_type' => 'CreditNote',
        'refundable_id' => $creditNote->id,
        'provider_refund_id' => 're_wh_fail',
        'status' => 'pending',
    ]);

    $result = StripeService::updateStatus('re_wh_fail', 'failed');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('refund_failed')
        ->and($creditNote->refresh()->refundStatusEnum())->toBe(CreditNoteRefundStatus::Failed);

    Queue::assertPushed(SendWebhookJob::class, fn(SendWebhookJob $job): bool => $job->webhookType === 'credit_note.provider_refund_failure'
        && $job->object->is($creditNote)
        && $job->options['provider_customer_id'] === 'prov_cus_1'
        && $job->options['provider_error'] === [
            'message' => 'Payment refund failed',
            'error_code' => null,
        ]);
});
