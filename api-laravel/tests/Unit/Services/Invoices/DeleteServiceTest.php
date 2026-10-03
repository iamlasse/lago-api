<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use Illuminate\Support\Facades\Queue;
use App\Services\Invoices\DeleteService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\MethodNotAllowedFailure;

uses()->group('ledger:svc:Invoices.DeleteService');

/**
 * Port of Rails' spec/services/invoices/delete_service_spec.rb — the
 * credit-note cascade is TODO(port) (credit notes are unported).
 */
it('marks a draft invoice as deleted', function (): void {
    $invoice = Invoice::factory()->draft()->create();

    $result = new DeleteService(invoice: $invoice)->execute();

    expect($result->success())->toBeTrue();
    expect($result->invoice->isDeleted())->toBeTrue();
    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Deleted);
});

it('enqueues the invoice.deleted webhook', function (): void {
    Queue::fake();

    $invoice = Invoice::factory()->draft()->create();

    new DeleteService(invoice: $invoice)->execute();

    Queue::assertPushed(
        SendWebhookJob::class,
        fn (SendWebhookJob $job): bool => $job->webhookType === 'invoice.deleted',
    );
});

it('returns a not found failure when the invoice is missing', function (): void {
    $result = new DeleteService(invoice: null)->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
    expect($result->getError()->resource)->toBe('invoice');
});

it('returns not_deletable when the invoice is not a draft', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Finalized]);

    $result = new DeleteService(invoice: $invoice)->execute();

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('not_deletable');
    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Finalized);
});

it('does not enqueue a webhook when the invoice is not deletable', function (): void {
    Queue::fake();

    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Finalized]);

    new DeleteService(invoice: $invoice)->execute();

    Queue::assertNotPushed(SendWebhookJob::class);
});
