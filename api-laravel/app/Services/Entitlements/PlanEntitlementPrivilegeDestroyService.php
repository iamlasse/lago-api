<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Entitlement;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Entitlement::PlanEntitlementPrivilegeDestroyService
 * (app/services/entitlement/plan_entitlement_privilege_destroy_service.rb)
 * — discards the one entitlement value for the privilege, then reloads the
 * entitlement with the associations the serializer needs.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable "plan.updated").
 * - TODO(port): SendWebhookJob.perform_after_commit("plan.updated",
 *   entitlement.plan).
 */
class PlanEntitlementPrivilegeDestroyService extends BaseService
{
    public function __construct(
        private readonly ?Entitlement $entitlement,
        private readonly string $privilegeCode,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('entitlement');
        $entitlement = $this->entitlement;

        if ($entitlement === null) {
            return $result->notFoundFailure('entitlement');
        }

        $entitlementValue = $this->findEntitlementValue($entitlement);

        if ($entitlementValue === null) {
            return $result->notFoundFailure('privilege');
        }

        try {
            DB::transaction(function () use ($entitlementValue): void {
                $entitlementValue->delete();
            });

            // TODO(port): SendWebhookJob.perform_after_commit("plan.updated",
            // entitlement.plan).

            // NOTE: reload the entitlement with all the associations required
            // to serialize it (Rails: Entitlement.includes(:feature,
            // values: :privilege).find_by(id:)).
            $result->entitlement = Entitlement::query()
                ->with('feature', 'values.privilege')
                ->find($entitlement->id);

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /** Rails: `find_entitlement_value`. */
    private function findEntitlementValue(Entitlement $entitlement): ?object
    {
        return $entitlement->values()
            ->whereHas('privilege', fn ($query) => $query->where('code', $this->privilegeCode))
            ->first();
    }
}
