<?php

declare(strict_types=1);

namespace App\Services\PaymentReceipts;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentReceipt;
use App\Support\ActiveStorage;

/**
 * Port of Rails' PaymentReceipts::GenerateXmlService
 * (app/services/payment_receipts/generate_xml_service.rb) — attaches the
 * receipt's `xml_file` (UBL e-invoice XML) when the billing entity is
 * einvoicing-eligible.
 *
 * TODO(port): the XML renderer itself (EInvoices::Payments::Ubl::CreateService)
 * is a later slice — the attach step runs once that lands; until then the
 * service answers success without a file, exactly like the ported invoice
 * download_xml endpoint's ok-without-file shape.
 */
class GenerateXmlService extends BaseService
{
    public function __construct(
        private readonly ?PaymentReceipt $paymentReceipt,
        private readonly ?string $context = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_receipt');

        if ($this->paymentReceipt === null) {
            return $result->notFoundFailure('payment_receipt');
        }

        if ($this->shouldGenerateXml()) {
            $this->generateXml();
        }

        $result->payment_receipt = $this->paymentReceipt;

        return $result;
    }

    private function generateXml(): void
    {
        // TODO(port): EInvoices::Payments::Ubl::CreateService.call(payment:) —
        // build and attach payment_receipt.number + '.xml' (application/xml)
        // through ActiveStorage once the UBL renderer is ported. Rails wraps
        // the build in I18n.with_locale(payment.customer.preferred_document_locale).
    }

    /** Rails: `context == "admin" || xml_file.blank? && e_invoicing_enabled?`. */
    private function shouldGenerateXml(): bool
    {
        if ($this->context === 'admin') {
            return true;
        }

        return ! $this->paymentReceipt->hasXmlFile()
            && $this->paymentReceipt->billingEntity?->eligibleForEinvoicing() === true;
    }
}
