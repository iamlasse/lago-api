<?php

declare(strict_types=1);

namespace App\Services\AdjustedFees;

use App\Models\Fee;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\RefreshDraftService;

/**
 * Port of Rails' AdjustedFees::DestroyService
 * (app/services/adjusted_fees/destroy_service.rb): removes the fee's
 * adjustment and refreshes the draft invoice back to its computed values.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?Fee $fee,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fee');

        try {
            if ($this->fee === null) {
                return $result->notFoundFailure('fee');
            }

            if ($this->fee->adjustedFee === null) {
                return $result->notFoundFailure('adjusted_fee');
            }

            $this->fee->adjustedFee->delete(); // Rails: destroy!

            RefreshDraftService::call(invoice: $this->fee->invoice)->raiseIfError();

            $result->fee = $this->fee;

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }
}
