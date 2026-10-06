<?php

declare(strict_types=1);

namespace App\Services\IntegrationCustomers;

use App\Models\Customer;
use App\Models\Integration;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Models\IntegrationCustomer;

/**
 * Port of Rails' IntegrationCustomers::CreateConnectionService
 * (app/services/integration_customers/create_connection_service.rb) — the
 * GraphQL mutation wrapper around IntegrationCustomers::CreateService: the
 * connection is created only when an external id links it or the
 * sync_with_provider flag is set, and the first connection of a category
 * becomes the default one.
 */
class CreateConnectionService extends BaseService
{
    public function __construct(
        public readonly ?Customer $customer,
        /** @var array<string, mixed> */
        public readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('integration_customer');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        $integration = $this->customer->organization->integrations()
            ->where('id', $this->params['integration_id'] ?? null)
            ->first();

        if ($integration === null) {
            return $result->notFoundFailure('integration');
        }

        $customerType = $this->customerType($integration);

        if ($customerType === null) {
            return $result->singleValidationFailure('value_is_invalid', 'integration_id');
        }

        // Rails: return result if customer.partner_account?
        if ($this->customer->partnerAccount()) {
            return $result;
        }

        // Rails: create_integration_customer? — an explicit external id or
        // the sync flag; otherwise the mutation answers with no payload.
        $syncWithProvider = filter_var($this->params['sync_with_provider'] ?? false, FILTER_VALIDATE_BOOL);
        $externalCustomerId = $this->params['external_customer_id'] ?? null;

        if ($externalCustomerId === null && ! $syncWithProvider) {
            return $result;
        }

        $category = IntegrationCustomer::CATEGORY_BY_TYPE[$customerType] ?? 'tax';

        try {
            $integrationCustomer = DB::transaction(function () use ($integration, $customer, $category): ?IntegrationCustomer {
                $firstConnection = ! $customer->integrationCustomers()
                    ->where('category', $category)
                    ->exists();

                $created = CreateService::callBang(
                    params: array_merge($this->params, [
                        'integration_type' => $this->providerKey($integration),
                        'integration_code' => $integration->code,
                    ]),
                    integration: $integration,
                    customer: $customer,
                )->integration_customer;

                if ($created === null) {
                    return null;
                }

                $created->code = $this->params['code'] ?? $integration->code;
                $created->category = $category;
                $created->is_default = $firstConnection;
                $created->save();

                return $created;
            });
        } catch (\App\Services\Failures\FailedResult $e) {
            return $result->failWithError($e);
        }

        if ($integrationCustomer === null) {
            return $result;
        }

        $result->integration_customer = $integrationCustomer->refresh();

        return $result;
    }

    /** Rails: integration.provider_key — the STI type's provider short key. */
    private function providerKey(Integration $integration): ?string
    {
        return array_flip(IntegrationCustomer::PROVIDER_TYPES)[$integration->type] ?? null;
    }

    /** Rails: BaseCustomer.customer_type(integration_type) — nil when unknown. */
    private function customerType(Integration $integration): ?string
    {
        return IntegrationCustomer::PROVIDER_TYPES[$this->providerKey($integration) ?? ''] ?? null;
    }
}
