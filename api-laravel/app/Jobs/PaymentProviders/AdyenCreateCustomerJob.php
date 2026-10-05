<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use Throwable;
use Illuminate\Support\Facades\Log;
use App\Models\PaymentProviderCustomer;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviderCustomers\AdyenService;

/**
 * Port of Rails' PaymentProviderCustomers::AdyenCreateJob (queue :providers,
 * retry_on Adyen::AdyenError 6 attempts polynomially longer) — generates the
 * customer's checkout URL. Authentication errors are logged and dropped
 * (Rails: rescue -> nothing can be done on Lago's side).
 */
class AdyenCreateCustomerJob implements ShouldQueue
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
            AdyenService::callBang(action: AdyenService::CREATE, providerCustomer: $this->providerCustomer);
        } catch (Throwable $e) {
            Log::warning($e->getMessage());
        }
    }
}
