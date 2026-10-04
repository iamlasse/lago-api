<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Queue;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Invoices\Payments\MarkOverdueService;

uses()->group('ledger:svc:Invoices.Payments.MarkOverdueService');

/**
 * Port of Rails' spec/services/invoices/payments/mark_overdue_service_spec.rb.
 */
function markOverdueSetup(array $invoiceOverrides = []): array
{
    $organization = Organization::factory()->create();
    $invoice = Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'status' => InvoiceStatus::Finalized,
        'payment_status' => InvoicePaymentStatus::Pending,
        'payment_overdue' => false,
        'payment_due_date' => now('UTC')->subDay()->toDateString(),
    ], $invoiceOverrides));

    return [$organization, $invoice];
}

it('returns a failure when the invoice is nil', function (): void {
    $result = MarkOverdueService::call(invoice: null);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
    expect($result->getError()->resource)->toBe('invoice');
});

it('returns invoice_not_finalized when the invoice is a draft', function (): void {
    [, $invoice] = markOverdueSetup(['status' => InvoiceStatus::Draft]);

    $result = MarkOverdueService::call(invoice: $invoice);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('invoice_not_finalized');
});

it('returns invoice_payment_already_succeeded when the payment succeeded', function (): void {
    [, $invoice] = markOverdueSetup(['payment_status' => InvoicePaymentStatus::Succeeded]);

    $result = MarkOverdueService::call(invoice: $invoice);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('invoice_payment_already_succeeded');
});

it('returns invoice_due_date_in_future when the due date has not come', function (): void {
    [, $invoice] = markOverdueSetup([
        'payment_due_date' => now('UTC')->addDay()->toDateString(),
    ]);

    $result = MarkOverdueService::call(invoice: $invoice);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('invoice_due_date_in_future');
});

it('returns invoice_dispute_lost when a payment dispute was lost', function (): void {
    [, $invoice] = markOverdueSetup(['payment_dispute_lost_at' => now('UTC')]);

    $result = MarkOverdueService::call(invoice: $invoice);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('invoice_dispute_lost');
});

it('marks a past-due invoice as payment overdue', function (): void {
    [, $invoice] = markOverdueSetup();

    $result = MarkOverdueService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();
    expect($result->invoice->refresh()->isPaymentOverdue())->toBeTrue();
});

it('enqueues the invoice.payment_overdue webhook', function (): void {
    Queue::fake();

    [, $invoice] = markOverdueSetup();

    MarkOverdueService::call(invoice: $invoice);

    Queue::assertPushed(SendWebhookJob::class, function (SendWebhookJob $job) use ($invoice): bool {
        return $job->webhookType === 'invoice.payment_overdue'
            && $job->object instanceof Invoice
            && $job->object->is($invoice);
    });
});
