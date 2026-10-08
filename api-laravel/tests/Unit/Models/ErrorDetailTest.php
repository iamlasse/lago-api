<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\ErrorDetail;
use App\Enums\InvoiceStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

uses()->group('ledger:model:ErrorDetail');

/**
 * Port of Rails' spec/models/error_detail_spec.rb — the owner/organization
 * relations and the create_generation_error_for upsert semantics.
 */
it('belongs to an owner and an organization', function (): void {
    $errorDetail = ErrorDetail::factory()->create();

    expect($errorDetail->owner())->toBeInstanceOf(MorphTo::class);
    expect($errorDetail->organization())->toBeInstanceOf(BelongsTo::class);
});

it('does nothing if the invoice is nil', function (): void {
    expect(ErrorDetail::createGenerationErrorFor(null, new RuntimeException('x')))->toBeNull();
});

it('creates an error detail with link to invoice as an owner', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Generating]);

    $invoiceError = ErrorDetail::createGenerationErrorFor($invoice, new RuntimeException('boom'));

    expect($invoiceError)->not->toBeNull();
    expect($invoiceError->owner->is($invoice))->toBeTrue();
    expect($invoiceError->error_code)->toBe(ErrorDetail::ERROR_CODES['invoice_generation_error']);
});

it('stores the error in the details: error field', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Generating]);

    $invoiceError = ErrorDetail::createGenerationErrorFor($invoice, new RuntimeException('boom'));

    // Rails: error.inspect.to_json — the inspect string, JSON-encoded.
    expect($invoiceError->details['error'])->toBe(json_encode('#<RuntimeException: boom>'));
});

it('stores the backtrace in the details: backtrace field', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Generating]);

    $invoiceError = ErrorDetail::createGenerationErrorFor($invoice, new RuntimeException('boom'));

    expect($invoiceError->details['backtrace'])->toBeArray();
});

it('stores the subscriptions in the details: subscriptions field', function (): void {
    Queue::fake();
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Generating]);

    $invoiceError = ErrorDetail::createGenerationErrorFor($invoice, new RuntimeException('boom'));

    expect($invoiceError->details['subscriptions'])->toBe('[]');
});

it('updates when create_for is called with the same invoice', function (): void {
    Queue::fake();
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Generating]);

    $invoiceError = ErrorDetail::createGenerationErrorFor($invoice, new RuntimeException('boom'));
    $id = $invoiceError->id;

    $invoiceError = ErrorDetail::createGenerationErrorFor($invoice, new RuntimeException('boom again'));

    expect($invoiceError->id)->toBe($id);
    expect($invoiceError->details['error'])->toBe(json_encode('#<RuntimeException: boom again>'));
});
