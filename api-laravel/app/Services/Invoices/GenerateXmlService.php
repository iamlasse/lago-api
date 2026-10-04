<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Services\BaseResult;
use App\Support\ActiveStorage;

/**
 * Port of Rails' Invoices::GenerateXmlService
 * (app/services/invoices/generate_xml_service.rb) — attaches the UBL
 * e-invoice XML as the invoice's `xml_file` attachment when the billing
 * entity is eligible for e-invoicing.
 *
 * TODO(port): the XML renderer itself (EInvoices::Invoices::Ubl::CreateService)
 * is a separate, Gotenberg-independent pipeline and is not ported yet — the
 * gates below behave like Rails but no XML is produced, so the
 * download_xml endpoint keeps answering ok-without-file until it lands.
 */
class GenerateXmlService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Invoice $invoice,
        private readonly ?string $context = null,
    ) {
        parent::__construct();
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

        if ($this->shouldGenerateXml()) {
            // TODO(port): build the UBL XML (EInvoices::Invoices::Ubl::CreateService)
            // and ActiveStorage::attach(invoice, xml_file, ..., "{number}.xml",
            // "application/xml"); the invoice.save! follows in Rails.
        }

        $result->invoice = $this->invoice;

        return $result;
    }

    /**
     * Rails: should_generate_xml? — the admin context always regenerates;
     * otherwise an absent xml_file on an e-invoicing-eligible billing entity.
     */
    private function shouldGenerateXml(): bool
    {
        if ($this->context === 'admin') {
            return true;
        }

        return ActiveStorage::blob($this->invoice, ActiveStorage::XML_FILE) === null
            && $this->eInvoicingEnabled();
    }

    /** Rails: billing_entity.eligible_for_einvoicing?. */
    private function eInvoicingEnabled(): bool
    {
        return (bool) $this->invoice->billingEntity?->eligibleForEinvoicing();
    }
}
