<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;

/**
 * Port of Rails' PaymentProviderCustomers::FlutterwaveService — create /
 * update keep the local connection only (no provider-side customer);
 * generate_checkout_url is not supported ("feature_not_supported").
 */
class FlutterwaveService extends BaseService
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const GENERATE_CHECKOUT_URL = 'generate_checkout_url';

    public function __construct(
        private readonly string $action,
        private readonly PaymentProviderCustomer $providerCustomer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        return match ($this->action) {
            self::CREATE => $this->create(),
            self::UPDATE => static::makeResult(),
            self::GENERATE_CHECKOUT_URL => static::makeResult()->notAllowedFailure('feature_not_supported'),
            default => static::makeResult(),
        };
    }

    private function create(): BaseResult
    {
        $result = static::makeResult('flutterwave_customer');
        $result->flutterwave_customer = $this->providerCustomer;

        return $result;
    }
}
