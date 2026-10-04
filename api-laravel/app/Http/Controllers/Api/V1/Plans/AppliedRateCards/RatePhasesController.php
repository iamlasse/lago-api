<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans\AppliedRateCards;

use App\Models\RatePhase;
use App\Models\PlanRateCard;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\RatePhases\CreateService;
use App\Services\RatePhases\UpdateService;
use App\Http\Controllers\Api\ApiController;
use App\Serializers\V1\RatePhaseSerializer;
use App\Services\RatePhases\DestroyService;
use App\Serializers\Base\CollectionSerializer;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::PlanRateCards::RatePhasesController
 * (app/controllers/api/v2/plan_rate_cards/rate_phases_controller.rb) —
 * nested at /plans/:plan_code/applied_rate_cards/:code/rate_phases.
 */
class RatePhasesController extends ApiController
{
    use RequiresProductCatalog;

    protected ?string $resourceName = 'plan_rate_card';

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $planRateCard = $this->findPlanRateCard($request);

        if ($planRateCard === null) {
            throw new \App\Exceptions\Api\NotFoundException('applied_rate_card');
        }

        return $this->renderSerializerJson((new CollectionSerializer(
            $planRateCard->ratePhases()->with('rateOverride')->orderBy('position')->get(),
            RatePhaseSerializer::class,
            ['collection_name' => 'rate_phases'],
        ))->toJson());
    }

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $planRateCard = $this->findPlanRateCard($request);

        if ($planRateCard === null) {
            throw new \App\Exceptions\Api\NotFoundException('applied_rate_card');
        }

        $result = CreateService::call(
            planRateCard: $planRateCard,
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

    private function findPlanRateCard(Request $request): ?PlanRateCard
    {
        $catalogPlan = $this->currentOrganization()->catalogPlans()
            ->where('code', $request->route('plan_code'))
            ->first();

        if ($catalogPlan === null) {
            return null;
        }

        return $catalogPlan->appliedRateCards()
            ->select('plan_rate_cards.*')
            ->join('rate_cards', 'rate_cards.id', '=', 'plan_rate_cards.rate_card_id')
            ->where('rate_cards.code', $request->route('rate_card_code'))
            ->first();
    }

    private function findRatePhase(Request $request): ?RatePhase
    {
        $planRateCard = $this->findPlanRateCard($request);

        return $planRateCard?->ratePhases()->where('code', $request->route('code'))->first();
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
     * Port of Rails' `permitted_update_params` — permit drops an explicit
     * null, so carry one through: a rate_override can be cleared over REST
     * like it can over GraphQL.
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
