<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\ActiveStorage;

/**
 * Port of Rails' CreditNotes::GenerateXmlService
 * (app/services/credit_notes/generate_xml_service.rb) — generates and
 * attaches the credit note's UBL XML (`xml_file` attachment). Draft credit
 * notes answer the is_draft not_allowed failure; generation only runs when
 * e-invoicing is enabled for the billing entity (or the admin context forces
 * it).
 *
 * TODO(port): the UBL renderer (EInvoices::CreditNotes::Ubl::CreateService) —
 * the service guards and attachment follow Rails, but the XML bytes are not
 * produced until the e-invoicing slice lands (same as the invoice
 * GenerateXmlService port).
 */
class GenerateXmlService extends BaseService
{
    public function __construct(
        private readonly ?CreditNote $creditNote,
        private readonly ?string $context = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note');

        if ($this->creditNote === null) {
            return $result->notFoundFailure('credit_note');
        }

        if ($this->creditNote->isDraft()) {
            return $result->notAllowedFailure('is_draft');
        }

        if ($this->shouldGenerateXml()) {
            // TODO(port): build the UBL XML
            // (EInvoices::CreditNotes::Ubl::CreateService) and
            // ActiveStorage::attach(credit_note, xml_file, ..., "{number}.xml",
            // "application/xml"); the credit_note.save! follows in Rails.
        }

        $result->credit_note = $this->creditNote;

        return $result;
    }

    private function shouldGenerateXml(): bool
    {
        if ($this->context === 'admin') {
            return true;
        }

        return ActiveStorage::blob($this->creditNote, ActiveStorage::XML_FILE) === null
            && $this->eInvoicingEnabled();
    }

    /** Rails: billing_entity.eligible_for_einvoicing?. */
    private function eInvoicingEnabled(): bool
    {
        return (bool) $this->creditNote->billingEntity?->eligibleForEinvoicing();
    }
}
