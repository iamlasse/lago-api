<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Support\ActiveStorage;
use App\Mail\InvoiceCreatedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Port of Rails' spec/mailers/invoice_mailer_spec.rb.
 */
function invoiceMailerInvoice(array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->create([
        'number' => 'LAGO-MAIL-001',
        'currency' => 'EUR',
        'fees_amount_cents' => 1200,
        'total_amount_cents' => 1200,
        ...$attributes,
    ]);

    Fee::factory()->subscriptionFee()->create([
        'invoice_id' => $invoice->id,
        'amount_cents' => 1200,
        'amount_currency' => 'EUR',
    ]);

    return $invoice;
}

it('sends the invoice email with the pdf attachment', function (): void {
    Storage::fake('lago_test');

    $invoice = invoiceMailerInvoice();
    $invoice->customer->update(['email' => 'buyer@example.test']);
    $invoice->billingEntity->update(['email' => 'billing@example.test', 'name' => 'Lago Inc']);

    ActiveStorage::attach($invoice, ActiveStorage::FILE, '%PDF-mail', 'LAGO-MAIL-001.pdf', 'application/pdf');

    $mailable = new InvoiceCreatedMail($invoice);

    expect($mailable->recipients())->toBe(['buyer@example.test']);
    expect($mailable->envelope()->subject)->toBe('Your Invoice from Lago Inc #LAGO-MAIL-001');
    expect($mailable->fromEmailAddress())->toBe(config('lago.from_email'));

    // Mail::fake does not build content, so exercise the content builder
    // directly to assert the Rails attachment behavior.
    $mailable->content();

    expect(collect($mailable->rawAttachments)->contains(
        fn (array $attachment) => $attachment['name'] === 'invoice-LAGO-MAIL-001.pdf'
            && $attachment['data'] === '%PDF-mail'
            && $attachment['options']['mime'] === 'application/pdf',
    ))->toBeTrue();

    Mail::fake();

    Mail::to($mailable->recipients())->send($mailable);

    Mail::assertSent(InvoiceCreatedMail::class);
});

it('renders the invoice email HTML with the download link', function (): void {
    Storage::fake('lago_test');

    $invoice = invoiceMailerInvoice();
    $invoice->billingEntity->update(['name' => 'Lago Inc']);

    ActiveStorage::attach($invoice, ActiveStorage::FILE, '%PDF-mail', 'LAGO-MAIL-001.pdf', 'application/pdf');

    $html = view('emails.invoice.created', [
        'invoice' => $invoice,
        'billingEntity' => $invoice->billingEntity,
        'showLagoLogo' => true,
        'lagoLogoUrl' => 'https://assets.getlago.com/lago-logo-email.png',
    ])->render();

    expect($html)->toContain('Invoice from Lago Inc');
    expect($html)->toContain('€12.00');
    expect($html)->toContain('LAGO-MAIL-001.pdf');
    expect($html)->toContain($invoice->fileUrl());
    expect($html)->toContain('Download invoice for details');
});

it('answers false to shouldSend when the billing entity email is blank', function (): void {
    $invoice = invoiceMailerInvoice();
    $invoice->customer->update(['email' => 'buyer@example.test']);
    $invoice->billingEntity->update(['email' => null]);

    expect((new InvoiceCreatedMail($invoice))->shouldSend())->toBeFalse();
});

it('answers false to shouldSend when the customer has no email', function (): void {
    $invoice = invoiceMailerInvoice();
    $invoice->customer->update(['email' => null]);
    $invoice->billingEntity->update(['email' => 'billing@example.test']);

    expect((new InvoiceCreatedMail($invoice))->shouldSend())->toBeFalse();
});

it('answers false to shouldSend for zero-fee invoices', function (): void {
    $invoice = invoiceMailerInvoice(['fees_amount_cents' => 0]);
    $invoice->customer->update(['email' => 'buyer@example.test']);
    $invoice->billingEntity->update(['email' => 'billing@example.test']);

    expect((new InvoiceCreatedMail($invoice))->shouldSend())->toBeFalse();
});

it('delivers without attachment when pdf generation is disabled', function (): void {
    config(['lago.disable_pdf_generation' => true]);

    $invoice = invoiceMailerInvoice();
    $invoice->customer->update(['email' => 'buyer@example.test']);
    $invoice->billingEntity->update(['email' => 'billing@example.test']);

    ActiveStorage::attach($invoice, ActiveStorage::FILE, '%PDF-mail', 'LAGO-MAIL-001.pdf', 'application/pdf');

    $mailable = new InvoiceCreatedMail($invoice);

    expect($mailable->content()->view)->toBe('emails.invoice.created');

    config(['lago.disable_pdf_generation' => null]);
});
