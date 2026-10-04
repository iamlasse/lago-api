<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Models\CatalogPlan;
use App\Models\PlanRateCard;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Queries\PlanRateCardsQuery;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Services\PlanRateCards\CreateService;
use App\Services\PlanRateCards\UpdateService;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\PlanRateCardSerializer;
use App\Services\PlanRateCards\DestroyService;
use App\Http\Controllers\Concerns\RequiresProductCatalog;

/**
 * Port of Rails' Api::V2::PlanRateCardsController
 * (app/controllers/api/v2/plan_rate_cards_controller.rb) — a catalog
 * plan's applied rate cards, nested at /plans/:plan_code/applied_rate_cards.
 */
class AppliedRateCardsController extends ApiController
{
    use Pagination;
    use RequiresProductCatalog;

    protected ?string $resourceName = 'plan_rate_card';

    public function create(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $result = CreateService::call(
            catalogPlan: $this->findCatalogPlan($request),
            params: $this->createParams($request),
        );

        if ($result->success()) {
            return $this->renderPlanRateCard($result->plan_rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $planRateCard = $this->findPlanRateCard($request);

        if ($planRateCard === null) {
            throw new \App\Exceptions\Api\NotFoundException('applied_rate_card');
        }

        return $this->renderPlanRateCard($planRateCard);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $planRateCard = $this->findPlanRateCard($request);

        $result = UpdateService::call(
            planRateCard: $planRateCard,
            params: $this->updateParams($request),
        );

        if ($result->success()) {
            return $this->renderPlanRateCard($result->plan_rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        $planRateCard = $this->findPlanRateCard($request);

        $result = DestroyService::call(planRateCard: $planRateCard);

        if ($result->success()) {
            return $this->renderPlanRateCard($result->plan_rate_card);
        }

        $this->renderErrorResponse($result);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureProductCatalog();

        if ($this->findCatalogPlan($request, fail: false) === null) {
            throw new \App\Exceptions\Api\NotFoundException('plan');
        }

        $result = PlanRateCardsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: ['plan_code' => $request->route('plan_code')],
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->plan_rate_cards,
                PlanRateCardSerializer::class,
                [
                    'collection_name' => 'applied_rate_cards',
                    'meta' => $this->paginationMetadata($result->plan_rate_cards),
                ],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function renderPlanRateCard(PlanRateCard $planRateCard): JsonResponse
    {
        return $this->renderSerializerJson(
            (new PlanRateCardSerializer($planRateCard, ['root_name' => 'applied_rate_card']))->toJson(),
        );
    }

    private function findCatalogPlan(Request $request, bool $fail = true): ?CatalogPlan
    {
        $catalogPlan = $this->currentOrganization()->catalogPlans()
            ->where('code', $request->route('plan_code'))
            ->first();

        if ($catalogPlan === null && $fail) {
            throw new \App\Exceptions\Api\NotFoundException('plan');
        }

        return $catalogPlan;
    }

    private function findPlanRateCard(Request $request): ?PlanRateCard
    {
        $catalogPlan = $this->findCatalogPlan($request, fail: false);

        if ($catalogPlan === null) {
            return null;
        }

        return $catalogPlan->appliedRateCards()
            ->select('plan_rate_cards.*')
            ->join('rate_cards', 'rate_cards.id', '=', 'plan_rate_cards.rate_card_id')
            ->where('rate_cards.code', $request->route('rate_card_code'))
            ->first();
    }

    /**
     * Port of `params.require(:applied_rate_card).permit(..., rate_phases:
     * [...])` — structural card fields pass through so the override service
     * can reject them explicitly instead of strong params dropping them.
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        $appliedRateCard = $this->requireParams($request, 'applied_rate_card');

        return $this->permitParams($appliedRateCard, [
            'rate_card_code',
            'units',
            'rate_phases' => [$this->ratePhaseSchema()],
        ]);
    }

    /** @return array<string, mixed> */
    private function updateParams(Request $request): array
    {
        $appliedRateCard = $this->requireParams($request, 'applied_rate_card');

        return $this->permitParams($appliedRateCard, ['units']);
    }

    /** The shared rate_phase permit schema (create + update). */
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
                // Structural card fields pass through so the override
                // service can reject them explicitly.
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
