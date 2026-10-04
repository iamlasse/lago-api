<?php

declare(strict_types=1);

namespace App\Jobs\PaymentProviders;

use Throwable;
use Illuminate\Support\Facades\Log;
use App\Models\PaymentProviderCustomer;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentProviderCustomers\StripeService;

/**
 * Port of Rails' PaymentProviderCustomers::StripeCreateJob (queue
 * :providers) — creates the customer on Stripe (POST /v1/customers).
 *
 * Rails retry_on: Stripe APIConnectionError / APIError / RateLimitError,
 * 6 attempts, polynomially longer waits — the Laravel retry backoff is
 * expressed with maxExceptions + backoff(). Unauthorized failures are
 * logged and dropped (Rails: rescue -> Rails.logger.warn).
 */
class StripeCreateCustomerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 6;

    public function __construct(
        public readonly PaymentProviderCustomer $providerCustomer,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PROVIDERS'), FILTER_VALIDATE_BOOL) ? 'providers' : 'default');
    }

    /** Rails: `wait: :polynomially_longer` — 15s * (attempts ** 2)-ish curve. */
    public function backoff(): array
    {
        return [15, 60, 135, 240, 375];
    }

    public function handle(): void
    {
        try {
            StripeService::callBang(action: 'create', providerCustomer: $this->providerCustomer);
        } catch (Throwable $e) {
            // Rails: rescue BaseService::UnauthorizedFailure -> logger.warn.
            if ($e->getMessage() !== '' && str_contains(mb_strtolower($e->getMessage()), 'authentication failed')) {
                Log::warning($e->getMessage());

                return;
            }

            throw $e;
        }
    }
}
