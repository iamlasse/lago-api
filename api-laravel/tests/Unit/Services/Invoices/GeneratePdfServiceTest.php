<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Jobs\SendWebhookJob;
use App\Support\ActiveStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use App\Services\Invoices\GeneratePdfService;

/**
 * Port of Rails' spec/services/invoices/generate_pdf_service_spec.rb.
 */
function generatePdfInvoice(): Invoice
{
    $organization = App\Models\Organization::factory()->create();
    App\Models\WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create([
        'number' => 'LAGO-PDF-001',
        'currency' => 'EUR',
        'total_amount_cents' => 2400,
        'organization_id' => $organization->id,
    ]);

    Fee::factory()->subscriptionFee()->create([
        'invoice_id' => $invoice->id,
        'amount_cents' => 2400,
        'amount_currency' => 'EUR',
    ]);

    return $invoice;
}

it('refuses to generate a pdf for a draft invoice', function (): void {
    $invoice = Invoice::factory()->draft()->create();

    $result = GeneratePdfService::call(invoice: $invoice);

    expect($result->success())->toBeFalse();
    expect($result->getError()->code)->toBe('is_draft');
});

it('does nothing when pdf generation is disabled', function (): void {
    config(['lago.disable_pdf_generation' => true]);
    Queue::fake();
    Storage::fake('lago_test');

    $invoice = generatePdfInvoice();

    $result = GeneratePdfService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();
    expect($invoice->hasFile())->toBeFalse();
    Queue::assertNothingPushed();

    config(['lago.disable_pdf_generation' => null]);
});

it('generates and attaches the pdf then emits invoice.generated', function (): void {
    config(['lago.pdf_url' => 'http://gotenberg.test']);
    Queue::fake();
    Storage::fake('lago_test');

    Http::fake([
        'gotenberg.test/forms/chromium/convert/html' => Http::response('%PDF-1.7 invoice', 200),
    ]);

    $invoice = generatePdfInvoice();

    $result = GeneratePdfService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();
    expect($invoice->hasFile())->toBeTrue();

    $blob = ActiveStorage::blob($invoice, ActiveStorage::FILE);

    expect($blob->filename)->toBe('LAGO-PDF-001.pdf');
    expect(ActiveStorage::download($blob))->toBe('%PDF-1.7 invoice');

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'invoice.generated');
});

it('skips regeneration while a pdf is already attached', function (): void {
    config(['lago.pdf_url' => 'http://gotenberg.test']);
    Queue::fake();
    Storage::fake('lago_test');

    $invoice = generatePdfInvoice();

    ActiveStorage::attach($invoice, ActiveStorage::FILE, '%PDF-existing', 'LAGO-PDF-001.pdf', 'application/pdf');

    GeneratePdfService::call(invoice: $invoice);

    expect(ActiveStorage::download(ActiveStorage::blob($invoice, ActiveStorage::FILE)))->toBe('%PDF-existing');
});

it('regenerates in the admin context even when a pdf exists', function (): void {
    config(['lago.pdf_url' => 'http://gotenberg.test']);
    Queue::fake();
    Storage::fake('lago_test');

    Http::fake([
        'gotenberg.test/forms/chromium/convert/html' => Http::response('%PDF-regenerated', 200),
    ]);

    $invoice = generatePdfInvoice();

    ActiveStorage::attach($invoice, ActiveStorage::FILE, '%PDF-existing', 'LAGO-PDF-001.pdf', 'application/pdf');

    $result = GeneratePdfService::call(invoice: $invoice, context: 'admin');

    expect($result->success())->toBeTrue();
    expect(ActiveStorage::download(ActiveStorage::blob($invoice, ActiveStorage::FILE)))->toBe('%PDF-regenerated');
});
