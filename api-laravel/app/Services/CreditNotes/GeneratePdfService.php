<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\PdfGenerator;
use App\Support\ActiveStorage;
use Illuminate\Support\Facades\App;
use App\Support\Documents\CreditNotePdf;

/**
 * Port of Rails' CreditNotes::GeneratePdfService
 * (app/services/credit_notes/generate_pdf_service.rb) — renders the credit
 * note document through Gotenberg and attaches the PDF bytes as the credit
 * note's `file` ActiveStorage attachment. Only a finalized credit note
 * generates; the webhook fires on the generation run.
 *
 * TODO(port): the CII e-invoicing XML attach step
 * (EInvoices::CreditNotes::Cii::CreateService + Utils::PdfAttachmentService,
 * gated on billing_entity.eligible_for_einvoicing?) and
 * Utils::ActivityLog.produce(credit_note, "credit_note.generated").
 */
class GeneratePdfService extends BaseService
{
    public function __construct(
        private readonly ?CreditNote $creditNote,
        private readonly ?string $context = null,
    ) {
        parent::__construct();
    }

    /** Rails: GeneratePdfService#template. */
    public static function templateName(CreditNote $creditNote): string
    {
        return CreditNotePdf::templateName($creditNote);
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note');

        if ($this->creditNote === null || ! $this->creditNote->isFinalized()) {
            return $result->notFoundFailure('credit_note');
        }

        if ($this->shouldGeneratePdf()) {
            $this->generatePdf($this->creditNote);

            SendWebhookJob::performLater('credit_note.generated', $this->creditNote);

            // TODO(port): Utils::ActivityLog.produce(credit_note, "credit_note.generated").
        }

        $result->credit_note = $this->creditNote;

        return $result;
    }

    /** Rails: `render_html` — the template rendered for the credit note. */
    public function renderHtml(): string
    {
        return (new PdfGenerator(static::templateName($this->creditNote), $this->creditNote))->renderHtml();
    }

    private function generatePdf(CreditNote $creditNote): void
    {
        // Rails: I18n.with_locale(credit_note.customer.preferred_document_locale).
        $locale = $creditNote->customer?->preferredDocumentLocale();
        $previous = App::getLocale();
        App::setLocale($locale !== null && $locale !== '' ? $locale : 'en');

        try {
            $pdfContent = (new PdfGenerator(static::templateName($creditNote), $creditNote))->generate();

            // TODO(port): attach_cii — the CII e-invoice XML embedded in the
            // PDF when billing_entity.eligible_for_einvoicing?.

            ActiveStorage::attach(
                $creditNote,
                ActiveStorage::FILE,
                $pdfContent,
                $creditNote->number.'.pdf',
                'application/pdf',
            );

            $creditNote->save();
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
            || ActiveStorage::blob($this->creditNote, ActiveStorage::FILE) === null;
    }
}
