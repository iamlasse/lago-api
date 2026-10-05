<?php

declare(strict_types=1);

namespace App\Jobs\IntegrationCustomers;

use App\Models\Integration;
use App\Models\IntegrationCustomer;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\IntegrationCustomers\UpdateService;

/**
 * Port of Rails' IntegrationCustomers::UpdateJob
 * (app/jobs/integration_customers/update_job.rb).
 */
class UpdateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        /** @var array<string, mixed> */
        public readonly array $integration_customer_params,
        public readonly Integration $integration,
        public readonly IntegrationCustomer $integration_customer,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        UpdateService::callBang(
            params: $this->integration_customer_params,
            integration: $this->integration,
            integration_customer: $this->integration_customer,
        );
    }
}
