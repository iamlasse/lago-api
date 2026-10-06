<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' PaymentProviderCustomers::SetAsDefaultService
 * (app/services/payment_provider_customers/set_as_default_service.rb) —
 * "Set a payment connection as the default for the customer": clears the
 * default flag on the customer's other connections, then flags this one.
 */
class SetAsDefaultService extends BaseService
{
    public function __construct(
        private readonly ?PaymentProviderCustomer $paymentProviderCustomer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_provider_customer');

        if ($this->paymentProviderCustomer === null) {
            return $result->notFoundFailure('payment_provider_customer');
        }

        if ($this->paymentProviderCustomer->is_default) {
            $result->payment_provider_customer = $this->paymentProviderCustomer;

            return $result;
        }

        DB::transaction(function (): void {
            $customer = $this->paymentProviderCustomer->customer;

            if ($customer !== null) {
                $customer->paymentProviderCustomers()
                    ->where('payment_provider_customers.id', '!=', $this->paymentProviderCustomer->id)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $this->paymentProviderCustomer->is_default = true;
            $this->paymentProviderCustomer->save();
        });

        $result->payment_provider_customer = $this->paymentProviderCustomer;

        return $result;
    }
}
