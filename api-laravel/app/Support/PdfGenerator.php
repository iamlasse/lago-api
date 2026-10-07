<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use App\Models\Invoice;
use App\Models\CreditNote;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Http;
use App\Support\Documents\InvoicePdf;
use App\Support\Documents\CreditNotePdf;
use App\Support\Documents\PaymentReceiptPdf;

/**
 * Port of Rails' Utils::PdfGenerator
 * (app/services/utils/pdf_generator.rb) — renders the invoice HTML template
 * and converts it to PDF through Gotenberg's chromium endpoint
 * (LAGO_PDF_URL + /forms/chromium/convert/html), passing the rendered
 * document, the Lago PDF logo and the page footer as multipart files with
 * the same scale/margins.
 *
 * The document context is the invoice — or, since the payment-receipts and
 * credit-notes slices, a PaymentReceipt or a CreditNote (Rails passes any
 * context: `Utils::PdfGenerator.new(template:, context:)`).
 */
final readonly class PdfGenerator
{
    /** Rails: SlimHelper::PDF_LOGO_FILENAME (public/assets/images/). */
    public const string PDF_LOGO_FILENAME = 'lago-logo-invoice.png';

    public function __construct(
        private string $template,
        private Invoice|PaymentReceipt|CreditNote $invoice,
    ) {}

    /** Rails: `render_html` — the template rendered for the document context. */
    public function renderHtml(): string
    {
        return match (true) {
            $this->invoice instanceof Invoice => InvoicePdf::render($this->template, $this->invoice),
            $this->invoice instanceof CreditNote => CreditNotePdf::render($this->template, $this->invoice),
            default => PaymentReceiptPdf::render($this->template, $this->invoice),
        };
    }

    /** Rails: footer partial (templates/documents/footer). */
    public function renderFooter(): string
    {
        return view('documents.footer', match (true) {
            $this->invoice instanceof Invoice => InvoicePdf::sharedViewData($this->invoice),
            $this->invoice instanceof CreditNote => CreditNotePdf::sharedViewData($this->invoice),
            default => PaymentReceiptPdf::sharedViewData($this->invoice),
        })->render();
    }

    /**
     * Rails: `render_pdf` — the multipart POST to Gotenberg. Rails' HTTP
     * client retries transient errors with a 300s read timeout; the Laravel
     * Http client wraps retries per the calling job's backoff (the document
     * jobs retry 6 times, polynomially longer), so no in-call retry loop is
     * added here.
     */
    public function renderPdf(): string
    {
        $pdfUrl = config('lago.pdf_url');

        if ($pdfUrl === null || $pdfUrl === '') {
            // Rails would raise URI.join on nil (ArgumentError) inside the
            // job — surface an equally explicit failure.
            throw new RuntimeException('LAGO_PDF_URL is not configured');
        }

        $logoPath = public_path('assets/images/'.self::PDF_LOGO_FILENAME);

        $response = Http::timeout(300)
            ->asMultipart()
            ->attach('file1', $this->renderHtml(), 'index.html', ['Content-Type' => 'text/html'])
            ->attach('file2', (string) file_get_contents($logoPath), self::PDF_LOGO_FILENAME, ['Content-Type' => 'image/png'])
            ->attach('file3', $this->renderFooter(), 'footer.html', ['Content-Type' => 'text/html'])
            ->post(mb_rtrim($pdfUrl, '/').'/forms/chromium/convert/html', [
                'scale' => '1.28',
                'marginTop' => '0.42',
                'marginBottom' => '0.6',
                'marginLeft' => '0.42',
                'marginRight' => '0.42',
            ]);

        $response->throw();

        return $response->body();
    }

    /**
     * Rails: `call` — returns the PDF bytes (result.io StringIO). Retries
     * are owned by the queue job (6 attempts, polynomial backoff).
     */
    public function generate(): string
    {
        return $this->renderPdf();
    }
}
