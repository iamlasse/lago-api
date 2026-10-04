<?php

declare(strict_types=1);

namespace App\Jobs\WalletTransactions;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\WalletTransactions\CreateFromParamsService;

/**
 * Port of Rails' WalletTransactions::CreateJob
 * (app/jobs/wallet_transactions/create_job.rb) — creates wallet
 * transactions from API params (the initial wallet top-up, the recurring
 * rules' grants).
 *
 * ActiveRecord::StaleObjectError is handled in
 * WalletTransactions::CreateFromParamsService.
 */
class CreateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $organizationId,
        /** @var array<string, mixed> */
        public readonly array $params,
        public readonly bool $uniqueTransaction = false,
    ) {
        $this->onQueue('high_priority');
    }

    public function handle(): void
    {
        $organization = Organization::query()->findOrFail($this->organizationId);

        // Rails: `unique_transaction` only feeds lock_key_arguments
        // (deduplication), which the UniqueJob middleware keys off the whole
        // serialized job here.
        CreateFromParamsService::callBang(
            organization: $organization,
            params: $this->params,
        );
    }
}
