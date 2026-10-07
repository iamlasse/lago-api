<?php

declare(strict_types=1);

namespace App\Services\InvoiceCustomSections;

use App\Models\InvoiceCustomSection;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' InvoiceCustomSections::DestroyService
 * (app/services/invoice_custom_sections/destroy_service.rb): discards the
 * section and deselects it everywhere (DeselectAllService).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?InvoiceCustomSection $invoiceCustomSection,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice_custom_section');

        if ($this->invoiceCustomSection === null) {
            return $result->notFoundFailure('invoice_custom_section');
        }

        try {
            DB::transaction(function () use ($result): void {
                $this->invoiceCustomSection->delete(); // Rails: discard (deleted_at)

                DeselectAllService::callBang(section: $this->invoiceCustomSection);

                $result->invoice_custom_section = $this->invoiceCustomSection;
            });

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }
}
