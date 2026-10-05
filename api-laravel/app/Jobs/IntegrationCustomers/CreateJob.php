<?php

declare(strict_types=1);

namespace App\Jobs\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\IntegrationCustomers\CreateService;

/**
 * Port of Rails' IntegrationCustomers::CreateJob
 * (app/jobs/integration_customers/create_job.rb).
 *
 * TODO(port): `unique :until_executed` on the [integration, customer] lock
 * key (dedupe of create/update in quick succession) and the HttpError
 * retry_on table.
 */
class CreateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        /** @var array<string, mixed> */
        public readonly array $integration_customer_params,
        public readonly Integration $integration,
        public readonly Customer $customer,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        CreateService::callBang(
            params: $this->integration_customer_params,
            integration: $this->integration,
            customer: $this->customer,
        );
    }
}
