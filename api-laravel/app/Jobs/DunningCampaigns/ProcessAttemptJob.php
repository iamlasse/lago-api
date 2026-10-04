<?php

declare(strict_types=1);

namespace App\Jobs\DunningCampaigns;

use App\Models\Customer;
use App\Models\BillingEntity;
use Illuminate\Bus\Queueable;
use App\Models\DunningCampaignThreshold;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\DunningCampaigns\ProcessAttemptService;

/**
 * Port of Rails' DunningCampaigns::ProcessAttemptJob
 * (app/jobs/dunning_campaigns/process_attempt_job.rb, queue :default) — one
 * dunning attempt (customer x threshold x billing entity), fanned out by
 * ProcessCustomerService.
 */
class ProcessAttemptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $customerId,
        public readonly string $dunningCampaignThresholdId,
        public readonly string $billingEntityId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $customer = Customer::query()->find($this->customerId);
        $threshold = DunningCampaignThreshold::query()->find($this->dunningCampaignThresholdId);
        $billingEntity = BillingEntity::query()->find($this->billingEntityId);

        if ($customer === null || $threshold === null || $billingEntity === null) {
            return;
        }

        ProcessAttemptService::call(
            customer: $customer,
            dunningCampaignThreshold: $threshold,
            billingEntity: $billingEntity,
        );
    }
}
