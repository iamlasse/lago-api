<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use Illuminate\Http\Request;
use App\Queries\CustomersQuery;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Serializers\V1\CustomerSerializer;
use App\Services\Customers\DestroyService;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Services\Customers\UpsertFromApiService;

/**
 * Port of Rails' Api::V1::CustomersController (app/controllers/api/v1/
 * customers_controller.rb).
 *
 * Not ported (out of scope for this slice — dependencies do not exist yet):
 * portal_url / checkout_url (payment providers), current_usage /
 * projected_usage / past_usage (events store), and the nested
 * invoices/subscriptions subresources.
 */
class CustomersController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'customer';

    public function create(Request $request): JsonResponse
    {
        $result = UpsertFromApiService::call(
            organization: $this->currentOrganization(),
            params: $this->createParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).
            return $this->renderCustomer($result->customer);
        }

        $this->renderErrorResponse($result);
    }

    public function index(Request $request): JsonResponse
    {
        $filterParams = $this->indexFilterParams($request);

        $searchTerm = $filterParams['search_term'] ?? null;
        unset($filterParams['search_term']);

        /** @var list<string>|null $billingEntityCodes */
        $billingEntityCodes = $filterParams['billing_entity_codes'] ?? null;
        unset($filterParams['billing_entity_codes']);

        $billingEntityIds = null;

        if ($billingEntityCodes !== null && $billingEntityCodes !== []) {
            $billingEntities = $this->currentOrganization()
                ->allBillingEntities()
                ->whereIn('code', $billingEntityCodes)
                ->get();

            if ($billingEntities->count() !== count(array_unique($billingEntityCodes))) {
                throw new NotFoundException('billing_entity');
            }

            $billingEntityIds = $billingEntities->pluck('id')->all();
        }

        $result = CustomersQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            searchTerm: $searchTerm,
            filters: array_merge($filterParams, ['billing_entity_ids' => $billingEntityIds]),
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new \App\Serializers\Base\CollectionSerializer(
                    $result->customers,
                    CustomerSerializer::class,
                    [
                        'collection_name' => 'customers',
                        'meta' => $this->paginationMetadata($result->customers),
                        'includes' => ['taxes', 'integration_customers'],
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $customer = $this->currentOrganization()
            ->customers()
            ->where('external_id', $request->route('external_id'))
            ->first();

        if ($customer === null) {
            throw new NotFoundException('customer');
        }

        return $this->renderCustomer($customer);
    }

    public function destroy(Request $request): JsonResponse
    {
        $customer = $this->currentOrganization()
            ->customers()
            ->where('external_id', $request->route('external_id'))
            ->first();

        $result = DestroyService::call(customer: $customer);

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).
            return $this->renderCustomer($result->customer);
        }

        $this->renderErrorResponse($result);
    }

    /**
     * Port of `render_customer`: every single-customer response carries the
     * same includes, verbatim.
     */
    private function renderCustomer(Customer $customer): JsonResponse
    {
        return $this->renderSerializerJson((new CustomerSerializer(
            $customer,
            [
                'root_name' => 'customer',
                'includes' => ['taxes', 'integration_customers', 'applicable_invoice_custom_sections', 'error_details'],
            ],
        ))->toJson());
    }

    /**
     * Port of `params.expect(customer: [...])` — the create/upsert contract,
     * verbatim (Rails' permitted + required params).
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        /** @var mixed $customer */
        $customer = $this->requireParam($request, 'customer');

        if (! is_array($customer)) {
            throw new \App\Exceptions\Api\ParameterMissingException('customer');
        }

        return $this->permitParams($customer, [
            'account_type',
            'external_id',
            'name',
            'firstname',
            'lastname',
            'customer_type',
            'country',
            'address_line1',
            'address_line2',
            'state',
            'zipcode',
            'email',
            'city',
            'url',
            'phone',
            'logo_url',
            'legal_name',
            'legal_number',
            'tax_identification_number',
            'currency',
            'timezone',
            'net_payment_term',
            'external_salesforce_id',
            'finalize_zero_amount_invoice',
            'skip_invoice_custom_sections',
            'billing_entity_code',
            'integration_customers' => [[
                'id',
                'code',
                'external_customer_id',
                'integration_type',
                'integration_code',
                'subsidiary_id',
                'sync_with_provider',
                'targeted_object',
            ]],
            'billing_configuration' => [
                'invoice_grace_period',
                'subscription_invoice_issuing_date_anchor',
                'subscription_invoice_issuing_date_adjustment',
                'payment_provider',
                'payment_provider_code',
                'provider_customer_id',
                'sync',
                'sync_with_provider',
                'document_locale',
                'provider_payment_methods' => [],
            ],
            'metadata' => [[
                'id',
                'key',
                'value',
                'display_in_invoice',
            ]],
            'shipping_address' => [
                'address_line1',
                'address_line2',
                'city',
                'zipcode',
                'state',
                'country',
            ],
            'tax_codes' => [],
            'invoice_custom_section_codes' => [],
        ]);
    }

    /**
     * Port of the index `params.permit(...)` filter — only these query
     * params survive, with the same shape checks (a scalar sent where an
     * array is declared, e.g. `?billing_entity_codes=invalid_code`, is
     * dropped entirely).
     *
     * @return array<string, mixed>
     */
    private function indexFilterParams(Request $request): array
    {
        $query = $request->query();
        $filterParams = [];

        foreach (['search_term', 'has_tax_identification_number', 'has_customer_type', 'customer_type', 'external_id'] as $key) {
            if (array_key_exists($key, $query) && ! is_array($query[$key])) {
                $filterParams[$key] = $query[$key];
            }
        }

        foreach (['currencies', 'countries', 'states', 'zipcodes', 'billing_entity_codes', 'account_type'] as $key) {
            if (array_key_exists($key, $query) && is_array($query[$key])) {
                $filterParams[$key] = array_values(array_filter($query[$key], fn ($entry): bool => is_scalar($entry)));
            }
        }

        if (array_key_exists('metadata', $query) && is_array($query['metadata'])) {
            // Rails query params have no null: `?metadata[k]=` yields "".
            // (Laravel's ConvertEmptyStringsToNull collapses "" to null —
            // restored here so the presence/absence split sees strings.)
            $filterParams['metadata'] = array_map(
                fn ($value): mixed => is_array($value) ? $value : ($value ?? ''),
                $query['metadata'],
            );
        }

        return $filterParams;
    }
}
