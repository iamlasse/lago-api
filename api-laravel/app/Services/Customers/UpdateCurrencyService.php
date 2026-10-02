<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Customers::UpdateCurrencyService.
 */
class UpdateCurrencyService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
        private readonly ?string $currency,
        private readonly bool $customerUpdate = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();
        $customer = $this->customer;

        if ($customer === null) {
            return $result->notFoundFailure('customer');
        }

        if ($customer->currency === $this->currency) {
            return $result;
        }

        // Multi-currency: customer.currency becomes a default preference,
        // not a constraint.
        if ($this->customerUpdate || $customer->currency === null) {
            $customer->currency = $this->currency;

            $errors = $customer->validateAttributes();

            if ($errors !== []) {
                return $result->recordValidationFailure($errors);
            }

            $customer->save();
        }

        return $result;
    }
}
