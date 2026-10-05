<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Integration;
use App\Services\BaseResult;

/**
 * Port of Rails' IntegrationCustomers::BaseService
 * (app/services/integration_customers/base_service.rb) — the shared param
 * guards of the integration-customer services.
 */
abstract class BaseService extends \App\Services\BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        protected readonly array $params,
        protected readonly ?Integration $integration,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('integration_customer');

        if ($this->integration === null) {
            return $result->notFoundFailure('integration');
        }

        return $result;
    }

    /** Rails: `sync_with_provider` — the param boolean cast. */
    protected function sync_with_provider(): bool
    {
        return filter_var($this->params['sync_with_provider'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Rails: `customer_type` — the STI type per the integration_type param. */
    protected function customer_type(): ?string
    {
        return \App\Models\IntegrationCustomer::PROVIDER_TYPES[$this->params['integration_type'] ?? null] ?? null;
    }

    protected function subsidiary_id(): ?string
    {
        return $this->params['subsidiary_id'] ?? null;
    }

    protected function targeted_object(): ?string
    {
        return $this->params['targeted_object'] ?? null;
    }

    protected function external_customer_id(): ?string
    {
        return $this->params['external_customer_id'] ?? null;
    }

    protected function code(): ?string
    {
        return $this->params['code'] ?? null;
    }
}
