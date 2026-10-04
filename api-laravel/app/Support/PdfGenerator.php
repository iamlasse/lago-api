<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use App\Support\Documents\InvoicePdf;

/**
 * Port of Rails' Utils::PdfGenerator
 * (app/services/utils/pdf_generator.rb) — renders the invoice HTML template
 * and converts it to PDF through Gotenberg's chromium endpoint
 * (LAGO_PDF_URL + /forms/chromium/convert/html), passing the rendered
 * document, the Lago PDF logo and the page footer as multipart files with
 * the same scale/margins.
 */
final class PdfGenerator
{
    /** Rails: SlimHelper::PDF_LOGO_FILENAME (public/assets/images/). */
    public const PDF_LOGO_FILENAME = 'lago-logo-invoice.png';

    public function __construct(
        private readonly string $template,
        private readonly Invoice $invoice,
    ) {}

    /** Rails: `render_html` — the template rendered for the invoice context. */
    public function renderHtml(): string
    {
        return InvoicePdf::render($this->template, $this->invoice);
    }

    /** Rails: footer partial (templates/documents/footer). */
    public function renderFooter(): string
    {
        return view('documents.footer', InvoicePdf::sharedViewData($this->invoice))->render();
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
