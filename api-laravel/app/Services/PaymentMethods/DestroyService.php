<?php

declare(strict_types=1);

namespace App\Services\PaymentMethods;

use App\Services\BaseResult;
use App\Models\PaymentMethod;
use App\Services\BaseService;

/**
 * Port of Rails' PaymentMethods::DestroyService — clears the default flag
 * and discards (soft delete) the payment method.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?PaymentMethod $paymentMethod,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_method');

        if ($this->paymentMethod === null) {
            return $result->notFoundFailure('payment_method');
        }

        $this->paymentMethod->is_default = false;
        $this->paymentMethod->delete();

        $result->payment_method = $this->paymentMethod;

        return $result;
    }
}
