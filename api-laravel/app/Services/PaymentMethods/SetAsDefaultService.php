<?php

declare(strict_types=1);

namespace App\Services\PaymentMethods;

use App\Services\BaseResult;
use App\Models\PaymentMethod;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' PaymentMethods::SetAsDefaultService — atomically flips
 * the customer's default payment method (all siblings cleared first, then
 * this one set), under the customer payment_method lock.
 */
class SetAsDefaultService extends BaseService
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

        if ($this->paymentMethod->is_default) {
            $result->payment_method = $this->paymentMethod;

            return $result;
        }

        DB::transaction(function (): void {
            // Rails: Customers::LockService(scope: :payment_method) — the
            // advisory lock is TODO(port); the transaction + conditional
            // update keeps the uniqueness invariant for now.
            PaymentMethod::query()
                ->where('customer_id', $this->paymentMethod->customer_id)
                ->where('id', '!=', $this->paymentMethod->id)
                ->update(['is_default' => false, 'updated_at' => now()]);

            $this->paymentMethod->is_default = true;
            $this->paymentMethod->save();
        });

        $result->payment_method = $this->paymentMethod;

        return $result;
    }
}
