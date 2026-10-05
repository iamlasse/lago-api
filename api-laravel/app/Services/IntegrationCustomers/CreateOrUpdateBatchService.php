<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Models\IntegrationCustomer;
use App\Jobs\IntegrationCustomers\CreateJob;
use App\Jobs\IntegrationCustomers\UpdateJob;

/**
 * Port of Rails' IntegrationCustomers::CreateOrUpdateBatchService
 * (app/services/integration_customers/create_or_update_batch_service.rb) —
 * the integration_customers array on the customer payloads: for each entry,
 * find the matching integration by code, then dispatch the create or update
 * job (Rails' SYNC_INTEGRATIONS run inline; the ported tax providers are
 * queued).
 */
class CreateOrUpdateBatchService extends \App\Services\BaseService
{
    /** Rails: SYNC_INTEGRATIONS — the integration types synced inline. */
    public const array SYNC_INTEGRATIONS = ['Integrations::SalesforceIntegration'];

    /**
     * @param  list<array<string, mixed>>|null  $integration_customers
     */
    public function __construct(
        public readonly ?array $integration_customers,
        public readonly ?Customer $customer,
        public readonly bool $new_customer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();

        if ($this->integration_customers === null || $this->customer === null) {
            return $result;
        }

        if ($this->customer->partnerAccount()) {
            return $result;
        }

        foreach ($this->integration_customers as $params) {
            $integration = $this->integrationFor($params);

            if ($integration === null) {
                continue;
            }

            if ($this->customerTypeFor($params) === null) {
                continue;
            }

            $existing = $this->existingIntegrationCustomer($integration, $params);

            if ($this->shouldCreate($params, $existing)) {
                CreateJob::dispatch(
                    integration_customer_params: $params,
                    integration: $integration,
                    customer: $this->customer,
                );
            } elseif (! $this->new_customer && $existing !== null) {
                UpdateJob::dispatch(
                    integration_customer_params: $params,
                    integration: $integration,
                    integration_customer: $existing,
                );
            }
        }

        return $result;
    }

    /**
     * Rails: `sanitize_integration_customers` + `integration` — the entry's
     * integration looked up by its code on the organization.
     *
     * @param  array<string, mixed>  $params
     */
    private function integrationFor(array $params): ?Integration
    {
        $code = $params['integration_code'] ?? $params['code'] ?? null;

        if ($code === null) {
            return null;
        }

        return $this->customer->organization->integrations()
            ->where('code', $code)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function customerTypeFor(array $params): ?string
    {
        return IntegrationCustomer::PROVIDER_TYPES[$params['integration_type'] ?? null] ?? null;
    }

    /**
     * Rails: `integration_customer` — the existing row of the same type on
     * this customer (any integration of the matching code).
     *
     * @param  array<string, mixed>  $params
     */
    private function existingIntegrationCustomer(Integration $integration, array $params): ?IntegrationCustomer
    {
        $type = $this->customerTypeFor($params);

        if ($type === null) {
            return null;
        }

        return $this->customer->integrationCustomers()
            ->where('type', $type)
            ->first();
    }

    /**
     * Rails: `create_integration_customer?` — a new customer, or a customer
     * without this integration customer yet, plus a sync flag or an explicit
     * external id.
     *
     * @param  array<string, mixed>  $params
     */
    private function shouldCreate(array $params, ?IntegrationCustomer $existing): bool
    {
        $syncWithProvider = filter_var($params['sync_with_provider'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hasExternalId = ($params['external_customer_id'] ?? null) !== null;

        return ($this->new_customer || $existing === null) && ($syncWithProvider || $hasExternalId);
    }
}
