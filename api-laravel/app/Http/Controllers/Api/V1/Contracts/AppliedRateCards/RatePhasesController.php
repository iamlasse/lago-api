<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Contracts\AppliedRateCards;

use App\Models\Contract;
use App\Models\RatePhase;
use Illuminate\Http\Request;
use App\Models\ContractRateCard;
use Illuminate\Http\JsonResponse;
use App\Services\RatePhases\CreateService;
use App\Services\RatePhases\UpdateService;
use App\Http\Controllers\Api\ApiController;
use App\Serializers\V1\RatePhaseSerializer;
use App\Services\RatePhases\DestroyService;
use App\Serializers\Base\CollectionSerializer;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::ContractRateCards::RatePhasesController
 * (app/controllers/api/v2/contract_rate_cards/rate_phases_controller.rb) —
 * nested at
 * /contracts/:external_id/applied_rate_cards/:code/rate_phases.
 */
class RatePhasesController extends ApiController
{
    use RequiresProductCatalog;

    protected ?string $resourceName = 'contract_rate_card';

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $contractRateCard = $this->findContractRateCard($request);

        if ($contractRateCard === null) {
            throw new \App\Exceptions\Api\NotFoundException('applied_rate_card');
        }

        return $this->renderSerializerJson((new CollectionSerializer(
            $contractRateCard->ratePhases()->with('rateOverride')->orderBy('position')->get(),
            RatePhaseSerializer::class,
            ['collection_name' => 'rate_phases'],
        ))->toJson());
    }

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $contractRateCard = $this->findContractRateCard($request);

        if ($contractRateCard === null) {
            throw new \App\Exceptions\Api\NotFoundException('applied_rate_card');
        }

        $result = CreateService::call(
            contractRateCard: $contractRateCard,
            params: $this->createParams($request),
        );

        if ($result->success()) {
            return $this->renderRatePhase($result->rate_phase);
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $ratePhase = $this->findRatePhase($request);

        if ($ratePhase === null) {
            throw new \App\Exceptions\Api\NotFoundException('rate_phase');
        }

        $result = UpdateService::call(
            ratePhase: $ratePhase,
            params: $this->updateParams($request),
        );

        if ($result->success()) {
            return $this->renderRatePhase($result->rate_phase);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $ratePhase = $this->findRatePhase($request);

        if ($ratePhase === null) {
            throw new \App\Exceptions\Api\NotFoundException('rate_phase');
        }

        $result = DestroyService::call(ratePhase: $ratePhase);

        if ($result->success()) {
            return $this->renderRatePhase($result->rate_phase);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function findContractRateCard(Request $request): ?ContractRateCard
    {
        $contract = Contract::liveByExternalId(
            (string) $request->route('external_id'),
            $this->currentOrganization()->id,
        );

        if ($contract === null) {
            return null;
        }

        return $contract->appliedRateCards()
            ->select('contract_rate_cards.*')
            ->join('rate_cards', 'rate_cards.id', '=', 'contract_rate_cards.rate_card_id')
            ->where('rate_cards.code', $request->route('rate_card_code'))
            ->first();
    }

    private function findRatePhase(Request $request): ?RatePhase
    {
        $contractRateCard = $this->findContractRateCard($request);

        return $contractRateCard?->ratePhases()->where('code', $request->route('code'))->first();
    }

    private function renderRatePhase(RatePhase $ratePhase): JsonResponse
    {
        return $this->renderSerializerJson((new RatePhaseSerializer($ratePhase, ['root_name' => 'rate_phase']))->toJson());
    }

    /** @return array<string, mixed> */
    private function createParams(Request $request): array
    {
        $ratePhase = $this->requireParams($request, 'rate_phase');

        return $this->permitParams($ratePhase, $this->schema());
    }

    /**
     * Permit drops an explicit null; carry it through so an override can be
     * cleared over REST like it can over GraphQL.
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        $ratePhase = $this->requireParams($request, 'rate_phase');
        $permitted = $this->permitParams($ratePhase, $this->schema());

        if (array_key_exists('rate_override', $ratePhase) && $ratePhase['rate_override'] === null) {
            $permitted['rate_override'] = null;
        }

        return $permitted;
    }

    /** @return array<int|string, mixed> */
    private function schema(): array
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
