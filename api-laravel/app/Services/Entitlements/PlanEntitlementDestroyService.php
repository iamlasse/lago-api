<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Entitlement;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Entitlement::PlanEntitlementDestroyService
 * (app/services/entitlement/plan_entitlement_destroy_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable "plan.updated").
 * - TODO(port): SendWebhookJob.perform_after_commit("plan.updated",
 *   entitlement.plan).
 */
class PlanEntitlementDestroyService extends BaseService
{
    public function __construct(
        private readonly ?Entitlement $entitlement,
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

        try {
            DB::transaction(function () use ($entitlement): void {
                // Rails: discard_all! / discard! — soft deletes.
                $entitlement->values()->delete();
                $entitlement->delete();
            });

            // TODO(port): SendWebhookJob.perform_after_commit("plan.updated",
            // entitlement.plan).

            $result->entitlement = $entitlement;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
