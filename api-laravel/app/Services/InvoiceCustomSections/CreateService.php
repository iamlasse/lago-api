<?php

declare(strict_types=1);

namespace App\Services\InvoiceCustomSections;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' InvoiceCustomSections::CreateService
 * (app/services/invoice_custom_sections/create_service.rb).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $createParams,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice_custom_section');

        try {
            $invoiceCustomSection = $this->organization->invoiceCustomSections()->make($this->createParams);

            $errors = $invoiceCustomSection->validateAttributes();

            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $invoiceCustomSection->save();

            $result->invoice_custom_section = $invoiceCustomSection;

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }
}
