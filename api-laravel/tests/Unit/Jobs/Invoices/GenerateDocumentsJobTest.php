<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Jobs\SendWebhookJob;
use App\Jobs\Invoices\NotifyJob;
use App\Mail\InvoiceCreatedMail;
use App\Jobs\Invoices\DocumentsJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Jobs\Invoices\GeneratePdfJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use App\Jobs\Invoices\GenerateDocumentsJob;
use App\Jobs\Invoices\GeneratePdfAndNotifyJob;

/**
 * Port of Rails' spec/jobs/invoices/generate_documents_job_spec.rb,
 * generate_pdf_job_spec.rb, generate_pdf_and_notify_job_spec.rb and
 * notify_job_spec.rb.
 */
function documentsInvoice(): Invoice
{
    $invoice = Invoice::factory()->create([
        'number' => 'LAGO-JOB-001',
        'fees_amount_cents' => 900,
        'total_amount_cents' => 900,
    ]);

    Fee::factory()->subscriptionFee()->create([
        'invoice_id' => $invoice->id,
        'amount_cents' => 900,
        'amount_currency' => 'EUR',
    ]);

    return $invoice;
}

function withSidekiqPdfs(bool $enabled, callable $scenario): void
{
    putenv('SIDEKIQ_PDFS='.($enabled ? 'true' : ''));

    try {
        $scenario();
    } finally {
        putenv('SIDEKIQ_PDFS');
    }
}

it('routes document jobs to pdfs when SIDEKIQ_PDFS is set', function (): void {
    withSidekiqPdfs(true, fn (): mixed => expect(DocumentsJob::queueName())->toBe('pdfs'));
    withSidekiqPdfs(false, fn (): mixed => expect(DocumentsJob::queueName())->toBe('invoices'));
});

it('generates documents and notifies when requested', function (): void {
    config(['lago.pdf_url' => 'http://gotenberg.test']);
    Http::fake(['gotenberg.test/*' => Http::response('%PDF-documents', 200)]);
    Queue::fake();
    Storage::fake('lago_test');

    $invoice = documentsInvoice();

    (new GenerateDocumentsJob($invoice, true))->handle();

    expect($invoice->hasFile())->toBeTrue();

    Queue::assertPushed(NotifyJob::class, fn (NotifyJob $job): bool => $job->invoice->is($invoice));
});

it('generates documents without notifying by default', function (): void {
    config(['lago.pdf_url' => 'http://gotenberg.test']);
    Http::fake(['gotenberg.test/*' => Http::response('%PDF-documents', 200)]);
    Queue::fake();
    Storage::fake('lago_test');

    $invoice = documentsInvoice();

    (new GenerateDocumentsJob($invoice))->handle();

    expect($invoice->hasFile())->toBeTrue();
    Queue::assertNotPushed(NotifyJob::class);
    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job): bool => $job->webhookType === 'invoice.generated');
});

it('fans out from GeneratePdfAndNotifyJob to GenerateDocumentsJob', function (): void {
    Queue::fake();

    $invoice = documentsInvoice();

    (new GeneratePdfAndNotifyJob($invoice, true))->handle();

    Queue::assertPushed(GenerateDocumentsJob::class, fn (GenerateDocumentsJob $job): bool => $job->invoice->is($invoice) && $job->notify === true);
});

it('sends the invoice email from NotifyJob', function (): void {
    Mail::fake();
    Storage::fake('lago_test');

    $invoice = documentsInvoice();
    $invoice->customer->update(['email' => 'customer@example.test']);

    (new NotifyJob($invoice))->handle();

    Mail::assertSent(InvoiceCreatedMail::class, fn (InvoiceCreatedMail $mail): bool => $mail->recipients() === ['customer@example.test']);
});

it('skips NotifyJob delivery for zero-fee invoices', function (): void {
    Mail::fake();

    $invoice = Invoice::factory()->create(['fees_amount_cents' => 0]);
    $invoice->customer->update(['email' => 'customer@example.test']);

    expect((new InvoiceCreatedMail($invoice))->shouldSend())->toBeFalse();

    (new NotifyJob($invoice))->handle();

    Mail::assertNothingSent();
});

it('uses the polynomially-longer backoff table', function (): void {
    $invoice = documentsInvoice();

    expect((new GeneratePdfJob($invoice))->backoff())->toBe([3, 18, 83, 258, 627]);
    expect((new GeneratePdfJob($invoice))->tries)->toBe(6);
});
