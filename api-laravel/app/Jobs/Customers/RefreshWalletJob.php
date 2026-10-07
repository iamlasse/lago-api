<?php

declare(strict_types=1);

namespace App\Jobs\Customers;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Customers\RefreshWalletsService;

/**
 * Port of Rails' Customers::RefreshWalletJob
 * (app/jobs/customers/refresh_wallet_job.rb) — refreshes a customer's
 * wallets' ongoing balance.
 *
 * `$walletIds` marks an explicitly requested refresh (e.g. a balance
 * increase) that must run even when the customer-wide
 * awaiting_wallet_refresh flag is not set. The refresh itself always covers
 * every wallet: the cascade makes allocations interdependent.
 *
 * TODO(port): the tax_error ValidationFailure branch (ErrorDetails model)
 * and the dedicated-workers queue routing.
 */
class RefreshWalletJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 6;

    public function __construct(
        public readonly Customer $customer,
        /** @var list<string>|null */
        public readonly ?array $walletIds = null,
    ) {
        $this->onQueue(
            filter_var(env('SIDEKIQ_WALLETS'), FILTER_VALIDATE_BOOL) ? 'wallets' : 'low_priority',
        );
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 2.hours`. */
    public function uniqueFor(): int
    {
        return 2 * 3600;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        if ($this->walletIds === null && ! (bool) $this->customer->awaiting_wallet_refresh) {
            return;
        }

        // Rails: `return if customer.error_details.tax_error.exists?` and the
        // ValidationFailure rescue storing the tax error on the customer —
        // the ErrorDetails model is not ported yet (TODO(port)); provider
        // taxation arrives with the integrations milestone.

        RefreshWalletsService::callBang(customer: $this->customer);
    }
}
