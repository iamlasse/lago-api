<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans\Entitlements;

use App\Models\Plan;
use App\Models\Entitlement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Serializers\V1\Entitlement\PlanEntitlementSerializer;
use App\Services\Entitlements\PlanEntitlementPrivilegeDestroyService;

/**
 * Port of Rails' Api::V1::Plans::Entitlements::PrivilegesController
 * (app/controllers/api/v1/plans/entitlements/privileges_controller.rb) —
 * the nested DELETE /plans/:plan_code/entitlements/:entitlement_code/
 * privileges/:code.
 */
class PrivilegesController extends ApiController
{
    public function destroy(Request $request): JsonResponse
    {
        $plan = $this->findPlan($request);
        $entitlement = $this->findEntitlement($plan, $request);

        $result = PlanEntitlementPrivilegeDestroyService::call(
            entitlement: $entitlement,
            privilegeCode: (string) $request->route('code'),
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new PlanEntitlementSerializer(
                $result->entitlement,
                ['root_name' => 'entitlement'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ----------------------------------------------------------------------

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

    private function findEntitlement(Plan $plan, Request $request): Entitlement
    {
        $entitlement = Entitlement::query()
            ->where('plan_id', $plan->id)
            ->where('organization_id', (string) $this->currentOrganization()->id)
            ->whereHas('feature', fn ($query) => $query->where('code', (string) $request->route('entitlement_code')))
            ->with('feature', 'values.privilege')
            ->first();

        if ($entitlement === null) {
            throw new NotFoundException('entitlement');
        }

        return $entitlement;
    }
}
