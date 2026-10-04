<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Queue;
use App\Services\Invoices\VoidService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\MethodNotAllowedFailure;

uses()->group('ledger:svc:Invoices.VoidService');

/**
 * Port of Rails' spec/services/invoices/void_service_spec.rb — the
 * wallet/coupon recredit and credit-note branches are TODO(port) (wallets
 * and credit notes are unported); the concurrent-void guard is asserted on
 * the observable state.
 */
it('returns a failure when the invoice is nil', function (): void {
    $result = VoidService::call(invoice: null, params: []);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
    expect($result->getError()->resource)->toBe('invoice');
});

it('returns not_voidable when the invoice is a draft', function (): void {
    $invoice = Invoice::factory()->draft()->create();

    $result = VoidService::call(invoice: $invoice, params: []);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('not_voidable');
});

it('returns not_voidable when the invoice is already voided', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Voided]);

    $result = VoidService::call(invoice: $invoice, params: []);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('not_voidable');
});

it('voids a finalized invoice and stamps voided_at', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Finalized,
        'payment_status' => InvoicePaymentStatus::Succeeded,
        'payment_overdue' => true,
    ]);

    $result = VoidService::call(invoice: $invoice, params: []);

    expect($result->success())->toBeTrue();
    expect($result->invoice->isVoided())->toBeTrue();
    expect($result->invoice->voided_at)->not->toBeNull();
    expect($invoice->refresh()->isPaymentOverdue())->toBeFalse();
    expect($invoice->refresh()->ready_for_payment_processing)->toBeFalse();
});

it('voids a finalized invoice whose payment is pending', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Finalized,
        'payment_status' => InvoicePaymentStatus::Pending,
    ]);

    $result = VoidService::call(invoice: $invoice, params: []);

    expect($result->success())->toBeTrue();
    expect($result->invoice->isVoided())->toBeTrue();
});

it('enqueues the invoice.voided webhook', function (): void {
    Queue::fake();

    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Finalized,
        'payment_status' => InvoicePaymentStatus::Pending,
    ]);

    VoidService::call(invoice: $invoice, params: []);

    Queue::assertPushed(
        SendWebhookJob::class,
        fn (SendWebhookJob $job): bool => $job->webhookType === 'invoice.voided',
    );
});

it('voids exactly once under concurrent calls', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Finalized,
        'payment_status' => InvoicePaymentStatus::Pending,
    ]);

    // Two independent instances of the same record racing the void.
    $results = [];

    $first = VoidService::call(invoice: Invoice::query()->find($invoice->id), params: []);
    $second = VoidService::call(invoice: Invoice::query()->find($invoice->id), params: []);

    $results = [$first, $second];

    $successes = count(array_filter($results, fn ($r) => $r->success()));
    $rejections = count(array_filter(
        $results,
        fn ($r) => ! $r->success() && $r->getError()?->code === 'not_voidable',
    ));

    expect($successes)->toBe(1);
    expect($rejections)->toBe(1);
    expect($invoice->refresh()->isVoided())->toBeTrue();
});

it('rejects generate_credit_note with amounts above the creditable amount', function (): void {
    config(['lago.license' => 'premium-license-token']);

    try {
        $invoice = Invoice::factory()->create([
            'status' => InvoiceStatus::Finalized,
            'payment_status' => InvoicePaymentStatus::Pending,
            'sub_total_including_taxes_amount_cents' => 100,
            'total_amount_cents' => 100,
        ]);

        $result = VoidService::call(
            invoice: $invoice,
            params: ['generate_credit_note' => true, 'credit_amount' => 200, 'refund_amount' => 0],
        );

        expect($result->success())->toBeFalse();
        expect($result->getError())->toBeInstanceOf(App\Services\Failures\ValidationFailure::class);
        expect($result->getError()->messages)->toHaveKey('credit_refund_amount');
    } finally {
        config(['lago.license' => null]);

    }
});
