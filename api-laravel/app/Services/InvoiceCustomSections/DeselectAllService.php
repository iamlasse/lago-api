<?php

declare(strict_types=1);

namespace App\Services\InvoiceCustomSections;

use App\Models\InvoiceCustomSection;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' InvoiceCustomSections::DeselectAllService
 * (app/services/invoice_custom_sections/deselect_all_service.rb): removes
 * every billing-entity and customer selection of a section (used when the
 * section is destroyed).
 */
class DeselectAllService extends BaseService
{
    public function __construct(
        private readonly InvoiceCustomSection $section,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice_custom_section');

        $this->section->billingEntityAppliedInvoiceCustomSections()->delete();
        $this->section->customerAppliedInvoiceCustomSections()->delete();

        $result->invoice_custom_section = $this->section;

        return $result;
    }
}
