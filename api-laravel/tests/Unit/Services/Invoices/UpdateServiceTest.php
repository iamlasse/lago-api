<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Queue;
use App\Services\Invoices\UpdateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\Failures\MethodNotAllowedFailure;

uses()->group('ledger:svc:Invoices.UpdateService');

/**
 * Port of Rails' spec/services/invoices/update_service_spec.rb — the
 * job/integration emission points that are still TODO(port) are asserted on
 * the observable state only.
 */
it('updates the invoice and clears the payment overdue flag when it settles', function (): void {
    $invoice = Invoice::factory()->create(['payment_overdue' => true]);

    $result = new UpdateService(
        invoice: $invoice,
        params: ['payment_status' => 'succeeded', 'total_paid_amount_cents' => 100],
    )->execute();

    expect($result->success())->toBeTrue();
    expect($result->invoice->is($invoice))->toBeTrue();
    expect($invoice->refresh()->isPaymentOverdue())->toBeFalse();
    expect($invoice->paymentStatusEnum())->toBe(InvoicePaymentStatus::Succeeded);
    expect($invoice->total_paid_amount_cents)->toBe(100);
});

it('returns an error when the invoice does not exist', function (): void {
    $result = new UpdateService(invoice: null, params: [])->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('rejects an invalid payment status', function (): void {
    $invoice = Invoice::factory()->create();

    $result = new UpdateService(invoice: $invoice, params: ['payment_status' => 'Foo Bar'])->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(ValidationFailure::class);
    expect($result->getError()->messages)->toHaveKey('payment_status');
    expect($result->getError()->messages['payment_status'])->toContain('value_is_invalid');
});

it('rejects a payment status update on a draft invoice', function (): void {
    $invoice = Invoice::factory()->draft()->create();

    $result = new UpdateService(
        invoice: $invoice,
        params: ['payment_status' => 'succeeded', 'total_paid_amount_cents' => 100],
    )->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('payment_status_update_on_draft_invoice');
});

it('updates a non-draft invoice payment status', function (): void {
    $invoice = Invoice::factory()->create();

    $result = new UpdateService(
        invoice: $invoice,
        params: ['payment_status' => 'succeeded'],
    )->execute();

    expect($result->success())->toBeTrue();
    expect($invoice->refresh()->paymentStatusEnum())->toBe(InvoicePaymentStatus::Succeeded);
});

it('rejects metadata on a draft invoice', function (): void {
    $invoice = Invoice::factory()->draft()->create();

    $result = new UpdateService(
        invoice: $invoice,
        params: ['metadata' => [['key' => 'Hello', 'value' => 'Hi']]],
    )->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('metadata_on_draft_invoice');
});

it('rejects more than five metadata objects', function (): void {
    $invoice = Invoice::factory()->create();

    $metadata = [];
    foreach (range(1, 6) as $i) {
        $metadata[] = ['key' => "key-{$i}", 'value' => "value-{$i}"];
    }

    $result = new UpdateService(invoice: $invoice, params: ['metadata' => $metadata])->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(ValidationFailure::class);
    expect($result->getError()->messages)->toHaveKey('metadata');
    expect($result->getError()->messages['metadata'])->toContain('invalid_count');
});

it('updates ready_for_payment_processing when the invoice is not voided', function (): void {
    $invoice = Invoice::factory()->create(['ready_for_payment_processing' => false]);

    $result = new UpdateService(
        invoice: $invoice,
        params: ['ready_for_payment_processing' => true],
    )->execute();

    expect($result->success())->toBeTrue();
    expect($invoice->refresh()->ready_for_payment_processing)->toBeTrue();
});

it('does not update ready_for_payment_processing on a voided invoice', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Voided, 'ready_for_payment_processing' => false]);

    $result = new UpdateService(
        invoice: $invoice,
        params: ['ready_for_payment_processing' => true],
    )->execute();

    expect($result->success())->toBeTrue();
    expect($invoice->refresh()->ready_for_payment_processing)->toBeFalse();
});

it('delivers the payment status updated webhook when notified and visible', function (): void {
    Queue::fake();

    $invoice = Invoice::factory()->create(['payment_status' => InvoicePaymentStatus::Pending]);

    $result = new UpdateService(
        invoice: $invoice,
        params: ['payment_status' => 'succeeded'],
        webhookNotification: true,
    )->execute();

    expect($result->success())->toBeTrue();
    Queue::assertPushed(
        SendWebhookJob::class,
        fn (SendWebhookJob $job): bool => $job->webhookType === 'invoice.payment_status_updated',
    );
});

it('does not deliver the webhook when the payment status has not changed', function (): void {
    Queue::fake();

    $invoice = Invoice::factory()->create(['payment_status' => InvoicePaymentStatus::Succeeded]);

    new UpdateService(
        invoice: $invoice,
        params: ['payment_status' => 'succeeded'],
        webhookNotification: true,
    )->execute();

    Queue::assertNotPushed(SendWebhookJob::class);
});

it('returns a validation failure when the invoice cannot be saved', function (): void {
    $invoice = Invoice::factory()->create();
    $invoice->issuing_date = null;

    // Rails: invoice.save(validate: false) then the service save! fails.
    $invoice->saveQuietly();

    $result = new UpdateService(invoice: $invoice, params: ['total_paid_amount_cents' => 5])->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(ValidationFailure::class);
    expect($result->getError()->messages['issuing_date'] ?? [])->toContain('value_is_mandatory');
});
