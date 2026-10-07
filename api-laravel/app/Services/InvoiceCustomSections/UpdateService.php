<?php

declare(strict_types=1);

namespace App\Services\InvoiceCustomSections;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\InvoiceCustomSection;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' InvoiceCustomSections::UpdateService
 * (app/services/invoice_custom_sections/update_service.rb).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?InvoiceCustomSection $invoiceCustomSection,
        private readonly array $updateParams,
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
            $this->invoiceCustomSection->fill($this->updateParams);

            $errors = $this->invoiceCustomSection->validateAttributes();

            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $this->invoiceCustomSection->save();

            $result->invoice_custom_section = $this->invoiceCustomSection;

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }
}
