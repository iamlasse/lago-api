<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Contracts;

use App\Models\Contract;
use Illuminate\Http\Request;
use App\Models\ContractRateCard;
use Illuminate\Http\JsonResponse;
use App\Queries\ContractRateCardsQuery;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Services\ContractRateCards\CreateService;
use App\Services\ContractRateCards\UpdateService;
use App\Services\ContractRateCards\DestroyService;
use App\Http\Controllers\Concerns\RequiresProductCatalog;
use App\Serializers\V1\ContractAppliedRateCardSerializer;

/**
 * Port of Rails' Api::V2::ContractRateCardsController
 * (app/controllers/api/v2/contract_rate_cards_controller.rb) — a contract's
 * applied rate cards, nested at
 * /contracts/:external_id/applied_rate_cards.
 */
class AppliedRateCardsController extends ApiController
{
    use Pagination;
    use RequiresProductCatalog;

    protected ?string $resourceName = 'contract_rate_card';

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $result = CreateService::call(
            contract: $this->findContract($request),
            params: $this->createParams($request),
        );

        if ($result->success()) {
            return $this->renderContractRateCard($result->contract_rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $contractRateCard = $this->findContractRateCard($request);

        if ($contractRateCard === null) {
            throw new \App\Exceptions\Api\NotFoundException('applied_rate_card');
        }

        return $this->renderContractRateCard($contractRateCard);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $contractRateCard = $this->findContractRateCard($request);

        $result = UpdateService::call(
            contractRateCard: $contractRateCard,
            params: $this->updateParams($request),
        );

        if ($result->success()) {
            return $this->renderContractRateCard($result->contract_rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $contractRateCard = $this->findContractRateCard($request);

        $result = DestroyService::call(contractRateCard: $contractRateCard);

        if ($result->success()) {
            return $this->renderContractRateCard($result->contract_rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        if ($this->findContract($request, fail: false) === null) {
            throw new \App\Exceptions\Api\NotFoundException('contract');
        }

        $result = ContractRateCardsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: [
                'external_id' => $request->route('external_id'),
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->contract_rate_cards,
                ContractAppliedRateCardSerializer::class,
                [
                    'collection_name' => 'applied_rate_cards',
                    'meta' => $this->paginationMetadata($result->contract_rate_cards),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function renderContractRateCard(ContractRateCard $contractRateCard): JsonResponse
    {
        return $this->renderSerializerJson(
            (new ContractAppliedRateCardSerializer($contractRateCard, ['root_name' => 'applied_rate_card']))->toJson(),
        );
    }

    private function findContract(Request $request, bool $fail = true): ?Contract
    {
        $contract = Contract::liveByExternalId(
            (string) $request->route('external_id'),
            $this->currentOrganization()->id,
        );

        if ($contract === null && $fail) {
            throw new \App\Exceptions\Api\NotFoundException('contract');
        }

        return $contract;
    }

    private function findContractRateCard(Request $request): ?ContractRateCard
    {
        $contract = $this->findContract($request, fail: false);

        if ($contract === null) {
            return null;
        }

        return $contract->appliedRateCards()
            ->select('contract_rate_cards.*')
            ->join('rate_cards', 'rate_cards.id', '=', 'contract_rate_cards.rate_card_id')
            ->where('rate_cards.code', $request->route('rate_card_code'))
            ->first();
    }

    /** @return array<string, mixed> */
    private function createParams(Request $request): array
    {
        $appliedRateCard = $this->requireParams($request, 'applied_rate_card');

        return $this->permitParams($appliedRateCard, [
            'rate_card_code',
            'units',
            'billing_anchor_date',
            'rate_phases' => [$this->ratePhaseSchema()],
        ]);
    }

    /** @return array<string, mixed> */
    private function updateParams(Request $request): array
    {
        $appliedRateCard = $this->requireParams($request, 'applied_rate_card');

        return $this->permitParams($appliedRateCard, ['units', 'billing_anchor_date']);
    }

    /** @return array<int|string, mixed> */
    private function ratePhaseSchema(): array
    {
        return [
            'code',
            'position',
            'name',
            'billing_interval_cycle_count',
            'rate_override' => [
                'rate_model',
                'min_amount_cents',
                'billing_interval_count',
                'billing_interval_unit',
                'pricing_unit_conversion_rate',
                'billing_timing',
                'currency',
                'proration',
                'display_on_invoice',
                'regroup_paid_fees',
                'applied_pricing_unit_code',
                'rate_properties' => '*',
            ],
        ];
    }
}
