<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Contract;
use Illuminate\Http\Request;
use App\Queries\ContractsQuery;
use App\Models\ContractRateCard;
use Illuminate\Http\JsonResponse;
use App\Services\Contracts\CreateService;
use App\Services\Contracts\UpdateService;
use App\Serializers\V1\ContractSerializer;
use App\Http\Controllers\Api\ApiController;
use App\Services\Contracts\TerminateService;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::ContractsController
 * (app/controllers/api/v2/contracts_controller.rb).
 */
class ContractsController extends ApiController
{
    use Pagination;
    use RequiresProductCatalog;

    protected ?string $resourceName = 'contract';

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $result = CreateService::call(
            organization: $this->currentOrganization(),
            params: $this->createParams($request),
        );

        if ($result->success()) {
            return $this->renderContract($result->contract);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $contract = Contract::liveByExternalId(
            (string) $request->route('external_id'),
            $this->currentOrganization()->id,
        );

        $result = UpdateService::call(
            contract: $contract,
            params: $this->updateParams($request),
        );

        if ($result->success()) {
            return $this->renderContract($result->contract);
        }

        $this->renderErrorResponse($result);
    }

    /**
     * A contract is never destroyed: DELETE ends its lifecycle. An active
     * contract is terminated, a pending one canceled — the service decides.
     */
    public function terminate(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $contract = Contract::terminatableByExternalId(
            (string) $request->route('external_id'),
            $this->currentOrganization()->id,
        );

        $result = TerminateService::call(contract: $contract);

        if ($result->success()) {
            return $this->renderContract($result->contract);
        }

        $this->renderErrorResponse($result);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        // Accept both ?status=pending and ?status[]=pending.
        $statuses = array_values(array_filter(
            (array) $request->query('status'),
            fn ($value): bool => $value !== null && $value !== '',
        ));

        $result = ContractsQuery::call(
            organization: $this->currentOrganization(),
            searchTerm: $request->query('search_term'),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'plan_code' => $request->query('plan_code'),
                'external_customer_id' => $request->query('external_customer_id'),
                'external_id' => $request->query('external_id'),
                'has_rate_overrides' => $request->query('has_rate_overrides'),
                'billing_entity_ids' => array_values(array_filter((array) $request->query('billing_entity_ids'))) ?: null,
                // Defaults to ["active"] like Rails.
                'status' => $statuses ?: ['active'],
            ],
        );

        if ($result->success()) {
            $contracts = $result->contracts;

            // One grouped count instead of one COUNT per row in the
            // serializer.
            $counts = ContractRateCard::query()
                ->whereNull('deleted_at')
                ->whereIn('contract_id', $contracts->getCollection()->pluck('id'))
                ->groupBy('contract_id')
                ->selectRaw('contract_id, COUNT(*) as count')
                ->pluck('count', 'contract_id');

            return $this->renderSerializerJson((new CollectionSerializer(
                $contracts,
                ContractSerializer::class,
                [
                    'collection_name' => 'contracts',
                    'meta' => $this->paginationMetadata($contracts),
                    'applied_rate_cards_counts' => $counts,
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        // No status filter resolves to the live contract (pending or
        // active); an explicit status reads a specific one, including
        // terminated/canceled history.
        $status = $request->query('status');

        $contract = $status !== null && $status !== ''
            ? $this->currentOrganization()->contracts()
                ->orderByDesc('started_at')
                ->where('external_id', $request->route('external_id'))
                ->where('status', in_array($status, array_values(Contract::STATUSES), true) ? $status : 'active')
                ->first()
            : Contract::liveByExternalId(
                (string) $request->route('external_id'),
                $this->currentOrganization()->id,
            );

        if ($contract === null) {
            throw new \App\Exceptions\Api\NotFoundException('contract');
        }

        return $this->renderSerializerJson((new ContractSerializer(
            $contract,
            ['root_name' => 'contract', 'includes' => ['applied_rate_cards']],
        ))->toJson());
    }

    // -- Helpers ---------------------------------------------------------------------

    private function renderContract(Contract $contract): JsonResponse
    {
        return $this->renderSerializerJson((new ContractSerializer(
            $contract,
            ['root_name' => 'contract', 'includes' => ['applied_rate_cards']],
        ))->toJson());
    }

    /**
     * Port of `params.require(:contract).permit(...)` — external_customer_id
     * and external_id are set at creation and address the contract.
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        $contract = $this->requireParams($request, 'contract');

        return $this->permitParams($contract, [
            'external_customer_id',
            'external_id',
            'name',
            'plan_code',
            'billing_time',
            'billing_anchor_date',
            'started_at',
            'ended_at',
        ]);
    }

    /** @return array<string, mixed> */
    private function updateParams(Request $request): array
    {
        $contract = $this->requireParams($request, 'contract');

        return $this->permitParams($contract, [
            'name',
            'plan_code',
            'billing_time',
            'billing_anchor_date',
            'started_at',
            'ended_at',
        ]);
    }
}
