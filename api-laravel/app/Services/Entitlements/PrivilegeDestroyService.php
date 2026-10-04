<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Privilege;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Entitlement::PrivilegeDestroyService
 * (app/services/entitlement/privilege_destroy_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable "feature.updated"
 *   on privilege.feature).
 * - TODO(port): the plan.updated activity logs + webhooks for
 *   privilege.feature.plans and SendWebhookJob("feature.updated",
 *   privilege.feature).
 */
class PrivilegeDestroyService extends BaseService
{
    public function __construct(
        private readonly ?Privilege $privilege,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('privilege');
        $privilege = $this->privilege;

        if ($privilege === null) {
            return $result->notFoundFailure('privilege');
        }

        try {
            DB::transaction(function () use ($privilege): void {
                // Rails: discard_all! / discard! — soft deletes.
                $privilege->values()->delete();
                $privilege->delete();
            });

            // TODO(port): webhooks — Rails: privilege.feature.plans.each do
            // |plan| Utils::ActivityLog.produce_after_commit(plan,
            // "plan.updated"); jobs << SendWebhookJob.new("plan.updated",
            // plan) end; perform_all_later(jobs) + SendWebhookJob
            // .perform_later("feature.updated", privilege.feature).

            $result->privilege = $privilege;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
