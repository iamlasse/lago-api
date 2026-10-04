<?php

declare(strict_types=1);

use App\Models\Fee;
use RuntimeException;
use App\Models\Invoice;
use App\Support\PdfGenerator;
use Illuminate\Support\Facades\Http;
use App\Services\Invoices\GeneratePdfService;

/**
 * Port of Rails' spec/services/utils/pdf_generator_spec.rb plus the invoice
 * HTML template snapshot assertions (spec/services/invoices/
 * generate_pdf_service_spec.rb renders and snapshots the HTML).
 */
function pdfGeneratorInvoice(): Invoice
{
    $invoice = Invoice::factory()->create([
        'number' => 'LAGO-2026-04-001',
        'currency' => 'EUR',
        'fees_amount_cents' => 1500,
        'sub_total_excluding_taxes_amount_cents' => 1500,
        'sub_total_including_taxes_amount_cents' => 1500,
        'total_amount_cents' => 1500,
    ]);

    Fee::factory()->subscriptionFee()->create([
        'invoice_id' => $invoice->id,
        'amount_cents' => 1500,
        'unit_amount_cents' => 1500,
        'amount_currency' => 'EUR',
    ]);

    return $invoice;
}

it('renders the v4 invoice HTML structure', function (): void {
    $invoice = pdfGeneratorInvoice();

    $html = (new PdfGenerator(GeneratePdfService::templateName($invoice), $invoice))->renderHtml();

    expect($html)->toContain('<!doctype html>');
    expect($html)->toContain('<title>Invoice</title>');
    expect($html)->toContain('LAGO-2026-04-001');
    expect($html)->toContain('invoice-resume-table');
    expect($html)->toContain('total-table');
    expect($html)->toContain('Invoice');
    expect($html)->toContain('€15.00');
});

it('renders the footer partial with page numbering', function (): void {
    $invoice = pdfGeneratorInvoice();

    $footer = (new PdfGenerator('documents.invoices.v4', $invoice))->renderFooter();

    expect($footer)->toContain('LAGO-2026-04-001');
    expect($footer)->toContain('Page <span class="pageNumber"></span> of <span class="totalPages"></span>');
});

it('posts the document, logo and footer to Gotenberg with Rails margins', function (): void {
    $invoice = pdfGeneratorInvoice();

    config(['lago.pdf_url' => 'http://gotenberg.test']);

    Http::fake([
        'gotenberg.test/forms/chromium/convert/html' => Http::response('%PDF-1.7 fake', 200),
    ]);

    $generator = new PdfGenerator(GeneratePdfService::templateName($invoice), $invoice);
    $pdf = $generator->generate();

    expect($pdf)->toBe('%PDF-1.7 fake');

    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'http://gotenberg.test/forms/chromium/convert/html') {
            return false;
        }

        $body = $request->body();

        return str_contains($body, 'filename="index.html"')
            && str_contains($body, 'filename="'.PdfGenerator::PDF_LOGO_FILENAME.'"')
            && str_contains($body, 'filename="footer.html"')
            && str_contains($body, 'name="scale"')
            && str_contains($body, '1.28')
            && str_contains($body, 'name="marginTop"')
            && str_contains($body, '0.42')
            && str_contains($body, 'name="marginBottom"')
            && str_contains($body, '0.6');
    });
});

it('raises when LAGO_PDF_URL is not configured', function (): void {
    config(['lago.pdf_url' => null]);

    $invoice = pdfGeneratorInvoice();

    (new PdfGenerator(GeneratePdfService::templateName($invoice), $invoice))->generate();
})->throws(RuntimeException::class, 'LAGO_PDF_URL is not configured');
