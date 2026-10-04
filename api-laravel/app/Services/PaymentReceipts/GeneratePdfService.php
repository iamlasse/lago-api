<?php

declare(strict_types=1);

namespace App\Services\PaymentReceipts;

use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\PdfGenerator;
use App\Models\PaymentReceipt;
use App\Support\ActiveStorage;
use Illuminate\Support\Facades\App;

/**
 * Port of Rails' PaymentReceipts::GeneratePdfService
 * (app/services/payment_receipts/generate_pdf_service.rb) — render the
 * receipt through Gotenberg (template "payment_receipts/v1") and attach the
 * PDF as the receipt's `file` attachment, then fire
 * "payment_receipt.generated".
 *
 * TODO(port): the CII e-invoice XML attach step (EInvoices::Payments::Cii
 * + Utils::PdfAttachmentService, gated on
 * billing_entity.eligible_for_einvoicing?) and
 * Utils::ActivityLog.produce(receipt, "payment_receipt.generated").
 */
class GeneratePdfService extends BaseService
{
    public function __construct(
        private readonly ?PaymentReceipt $paymentReceipt,
        private readonly ?string $context = null,
    ) {
        parent::__construct();
    }

    /** Rails: GeneratePdfService#template ("payment_receipts/v1"). */
    public static function templateName(): string
    {
        return 'documents.payment_receipts.v1';
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_receipt');

        if ($this->paymentReceipt === null) {
            return $result->notFoundFailure('payment_receipt');
        }

        if ($this->shouldGeneratePdf()) {
            $this->generatePdf();

            SendWebhookJob::performLater('payment_receipt.generated', $this->paymentReceipt);

            // TODO(port): Utils::ActivityLog.produce(receipt, "payment_receipt.generated").
        }

        $result->payment_receipt = $this->paymentReceipt;

        return $result;
    }

    /** Rails: render_html — the template rendered for the receipt. */
    public function renderHtml(): string
    {
        return (new PdfGenerator(static::templateName(), $this->paymentReceipt))->renderHtml();
    }

    private function generatePdf(): void
    {
        // Rails: I18n.with_locale(payment.customer.preferred_document_locale).
        $locale = $this->paymentReceipt->customer?->preferredDocumentLocale();
        $previous = App::getLocale();
        App::setLocale($locale !== null && $locale !== '' ? $locale : 'en');

        try {
            $pdfContent = (new PdfGenerator(static::templateName(), $this->paymentReceipt))->generate();

            // TODO(port): attach_cii — the CII e-invoice XML embedded in the
            // PDF when billing_entity.eligible_for_einvoicing?.

            ActiveStorage::attach(
                $this->paymentReceipt,
                ActiveStorage::FILE,
                $pdfContent,
                $this->paymentReceipt->number.'.pdf',
                'application/pdf',
            );

            $this->paymentReceipt->save();
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
            || ! $this->paymentReceipt->hasFile();
    }
}
