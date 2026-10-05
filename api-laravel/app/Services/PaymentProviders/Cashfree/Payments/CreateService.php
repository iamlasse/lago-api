<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Cashfree\Payments;

use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' PaymentProviders::Cashfree::Payments::CreateService — a
 * no-op: Cashfree payments ride the payment-link flow, so the attempt only
 * creates the pending Payment record (done by the caller) and the webhook
 * moves it to PAID later.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Payment $payment,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment', 'error_message', 'error_code', 'reraise', 'should_retry');

        $result->payment = $this->payment;

        return $result;
    }
}
