<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use Throwable;
use App\Models\PaymentProviderCustomer;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviderCustomers\GocardlessService;

/**
 * Port of Rails' PaymentProviderCustomers::GocardlessCreateJob (queue
 * :providers, retry_on GoCardlessPro errors 6 attempts polynomially
 * longer).
 */
class GocardlessCreateCustomerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 6;

    public function __construct(
        public readonly PaymentProviderCustomer $providerCustomer,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    /** Rails: `wait: :polynomially_longer`. */
    public function backoff(): array
    {
        return [15, 60, 135, 240, 375];
    }

    public function handle(): void
    {
        try {
            GocardlessService::callBang(action: GocardlessService::CREATE, providerCustomer: $this->providerCustomer);
        } catch (Throwable $e) {
            throw $e;
        }
    }
}
