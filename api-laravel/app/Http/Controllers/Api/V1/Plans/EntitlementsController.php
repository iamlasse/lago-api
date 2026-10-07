<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Models\Plan;
use App\Models\Entitlement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Serializers\Base\CollectionSerializer;
use App\Services\Entitlements\PlanEntitlementDestroyService;
use App\Services\Entitlements\PlanEntitlementsUpdateService;
use App\Serializers\V1\Entitlement\PlanEntitlementSerializer;

/**
 * Port of Rails' Api::V1::Plans::EntitlementsController
 * (app/controllers/api/v1/plans/entitlements_controller.rb) — the nested
 * plan entitlements subresource (Rails: resources :entitlements,
 * param: :code inside the plans draw). POST behaves as a full sync
 * (partial: false), PATCH as a partial update.
 */
class EntitlementsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);

        $entitlements = $this->planEntitlements($plan);

        return $this->renderSerializerJson((new CollectionSerializer(
            $entitlements,
            PlanEntitlementSerializer::class,
            ['collection_name' => 'entitlements'],
        ))->toJson());
    }

    public function show(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $entitlement = $this->findEntitlement($plan, $request);

        return $this->renderSerializerJson((new PlanEntitlementSerializer(
            $entitlement,
            ['root_name' => 'entitlement'],
        ))->toJson());
    }

    public function create(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);

        $result = PlanEntitlementsUpdateService::call(
            organization: $this->currentOrganization(),
            plan: $plan,
            entitlementsParams: $this->updateParams($request),
            partial: false,
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->entitlements,
                PlanEntitlementSerializer::class,
                ['collection_name' => 'entitlements'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);

        $result = PlanEntitlementsUpdateService::call(
            organization: $this->currentOrganization(),
            plan: $plan,
            entitlementsParams: $this->updateParams($request),
            partial: true,
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->entitlements,
                PlanEntitlementSerializer::class,
                ['collection_name' => 'entitlements'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $entitlement = $this->findEntitlement($plan, $request);

        $result = PlanEntitlementDestroyService::call(entitlement: $entitlement);

        if ($result->success()) {
            return $this->renderSerializerJson((new PlanEntitlementSerializer(
                $result->entitlement,
                ['root_name' => 'entitlement'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function findPlan(Request $request): Plan
    {
        $plan = $this->currentOrganization()
            ->plans()
            ->parents()
            ->where('code', $request->route('plan_code'))
            ->first();

        if ($plan === null) {
            throw new NotFoundException('plan');
        }

        return $plan;
    }

    /**
     * Rails: `find_entitlement` — the plan's entitlement whose feature has
     * the requested code.
     */
    private function findEntitlement(Plan $plan, Request $request): Entitlement
    {
        $entitlement = $this->planEntitlements($plan)
            ->firstWhere('feature.code', (string) $request->route('entitlement_code'));

        if ($entitlement === null) {
            throw new NotFoundException('entitlement');
        }

        return $entitlement;
    }

    private function planEntitlements(Plan $plan)
    {
        // Rails: current_organization.entitlements.joins(:feature)
        //   .where(plan: plan).includes(:feature, values: :privilege) —
        // tenancy is enforced by the plan lookup above (same organization),
        // so the entitlements scope is expressed on the model.
        return Entitlement::query()
            ->where('plan_id', $plan->id)
            ->where('organization_id', (string) $this->currentOrganization()->id)
            // Rails: .joins(:feature) — an inner join, so entitlements
            // whose feature was discarded drop out.
            ->whereHas('feature')
            ->with('feature', 'values.privilege')->oldest()
            ->get();
    }

    /**
     * Rails: `params.fetch(:entitlements, {}).permit!` — the hash of
     * feature code => {privilege code => value} is taken verbatim.
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        $entitlements = $request->input('entitlements');

        return is_array($entitlements) ? $entitlements : [];
    }
}
