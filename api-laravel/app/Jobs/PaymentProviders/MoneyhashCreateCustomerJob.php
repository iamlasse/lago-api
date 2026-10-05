<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use App\Models\PaymentProviderCustomer;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviderCustomers\MoneyhashService;

/**
 * Port of Rails' PaymentProviderCustomers::MoneyhashCreateJob (queue
 * :providers) — creates the customer on Moneyhash and generates the
 * checkout URL.
 */
class MoneyhashCreateCustomerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaymentProviderCustomer $providerCustomer,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    public function handle(): void
    {
        MoneyhashService::callBang(action: MoneyhashService::CREATE, providerCustomer: $this->providerCustomer);
    }
}
