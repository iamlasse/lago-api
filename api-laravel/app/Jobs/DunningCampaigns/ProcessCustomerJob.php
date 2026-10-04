<?php

declare(strict_types=1);

namespace App\Jobs\DunningCampaigns;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\DunningCampaigns\ProcessCustomerService;

/**
 * Port of Rails' DunningCampaigns::ProcessCustomerJob
 * (app/jobs/dunning_campaigns/process_customer_job.rb, queue :default) —
 * one customer's dunning pass, fanned out by BulkProcessService.
 */
class ProcessCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $customerId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $customer = Customer::query()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        ProcessCustomerService::call(customer: $customer);
    }
}
