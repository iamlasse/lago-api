<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Models\Feature;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Entitlement::FeatureDestroyService
 * (app/services/entitlement/feature_destroy_service.rb) — a discard, never
 * a hard delete: the entitlement values, entitlements and privileges go
 * first, then the feature.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable "feature.deleted").
 * - TODO(port): the plan.updated activity logs + webhooks for feature.plans
 *   and SendWebhookJob("feature.deleted", feature) — emission points marked
 *   below.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?Feature $feature,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('feature');
        $feature = $this->feature;

        if ($feature === null) {
            return $result->notFoundFailure('feature');
        }

        try {
            DB::transaction(function () use ($feature): void {
                // Rails: discard_all! — soft deletes on deleted_at.
                $feature->entitlementValues()->delete();
                $feature->entitlements()->delete();
                $feature->privileges()->delete();
                $feature->delete();
            });

            // TODO(port): webhooks — Rails: plans = feature.plans.to_a; for
            // each, Utils::ActivityLog.produce_after_commit(plan,
            // "plan.updated") + SendWebhookJob.new("plan.updated", plan),
            // performed after commit, then SendWebhookJob.perform_later(
            // "feature.deleted", feature).

            $result->feature = $feature;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
