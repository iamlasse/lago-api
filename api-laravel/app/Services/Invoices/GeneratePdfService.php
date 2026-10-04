<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Support\PdfGenerator;
use App\Support\ActiveStorage;
use Illuminate\Support\Facades\App;

/**
 * Port of Rails' Invoices::GeneratePdfService
 * (app/services/invoices/generate_pdf_service.rb) — renders the invoice
 * document through Gotenberg and attaches the PDF bytes as the invoice's
 * `file` ActiveStorage attachment.
 *
 * TODO(port): the CII e-invoicing XML attach step
 * (Utils::PdfAttachmentService + EInvoices::Invoices::Cii::CreateService,
 * gated on billing_entity.eligible_for_einvoicing?) and
 * Utils::ActivityLog.produce(invoice, "invoice.generated").
 */
class GeneratePdfService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Invoice $invoice,
        private readonly ?string $context = null,
    ) {
        parent::__construct();
    }

    /** Rails: GeneratePdfService#template. */
    public static function templateName(Invoice $invoice): string
    {
        return \App\Support\Documents\InvoicePdf::templateName($invoice);
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if ($this->invoice->isDraft()) {
            return $result->notAllowedFailure('is_draft');
        }

        if ($this->shouldGeneratePdf()) {
            $this->generatePdf();

            SendWebhookJob::performLater('invoice.generated', $this->invoice);

            // TODO(port): Utils::ActivityLog.produce(invoice, "invoice.generated").
        }

        $result->invoice = $this->invoice;

        return $result;
    }

    /** Rails: `render_html` — the template rendered for the invoice. */
    public function renderHtml(): string
    {
        return (new PdfGenerator(static::templateName($this->invoice), $this->invoice))->renderHtml();
    }

    private function generatePdf(): void
    {
        // Rails: I18n.with_locale(invoice.customer.preferred_document_locale).
        $locale = $this->invoice->customer?->preferredDocumentLocale();
        $previous = App::getLocale();
        App::setLocale($locale !== null && $locale !== '' ? $locale : 'en');

        try {
            $pdfContent = (new PdfGenerator(static::templateName($this->invoice), $this->invoice))->generate();

            // TODO(port): attach_cii — the CII e-invoice XML embedded in the
            // PDF when billing_entity.eligible_for_einvoicing?.

            ActiveStorage::attach(
                $this->invoice,
                ActiveStorage::FILE,
                $pdfContent,
                $this->invoice->number.'.pdf',
                'application/pdf',
            );

            $this->invoice->save();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Rails: should_generate_pdf? — LAGO_DISABLE_PDF_GENERATION short
     * circuits; otherwise generate unless a file is already attached (the
     * "admin" context always regenerates).
     */
    private function shouldGeneratePdf(): bool
    {
        if (config('lago.disable_pdf_generation')) {
            return false;
        }

        return $this->context === 'admin'
            || ActiveStorage::blob($this->invoice, ActiveStorage::FILE) === null;
    }
}
