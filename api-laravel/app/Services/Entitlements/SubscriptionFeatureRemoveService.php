<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Feature;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Models\SubscriptionFeatureRemoval;

/**
 * Port of Rails' Entitlement::SubscriptionFeatureRemoveService
 * (app/services/entitlement/subscription_feature_remove_service.rb) — the
 * DELETE on a subscription entitlement: the override is discarded and, when
 * the feature is inherited from the plan, a removal tombstone is recorded.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable
 *   "subscription.updated").
 * - TODO(port): SendWebhookJob.perform_after_commit("subscription.updated",
 *   subscription).
 */
class SubscriptionFeatureRemoveService extends BaseService
{
    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly string $featureCode,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('feature_code');
        $subscription = $this->subscription;

        if ($subscription === null) {
            return $result->notFoundFailure('subscription');
        }

        $feature = Feature::query()
            ->where('organization_id', $subscription->organization_id)
            ->where('code', $this->featureCode)
            ->first();

        if ($feature === null) {
            return $result->notFoundFailure('feature');
        }

        try {
            DB::transaction(function () use ($subscription, $feature): void {
                $this->deleteSubscriptionEntitlementIfExists($subscription, $feature);
                $this->deletePrivilegeRemovalsIfExists($subscription, $feature);
                $this->addFeatureRemovalIfFeatureIsInPlan($subscription, $feature);
            });

            // TODO(port): SendWebhookJob.perform_after_commit(
            //   "subscription.updated", subscription).

            $result->featureCode = $this->featureCode;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    private function deleteSubscriptionEntitlementIfExists(Subscription $subscription, Feature $feature): void
    {
        $entitlement = Entitlement::query()->where('subscription_id', $subscription->id)->where('entitlement_feature_id', $feature->id)->first();

        if ($entitlement === null) {
            return;
        }

        $entitlement->values()->update(['deleted_at' => now()]);
        $entitlement->delete();
    }

    private function deletePrivilegeRemovalsIfExists(Subscription $subscription, Feature $feature): void
    {
        SubscriptionFeatureRemoval::query()->where('subscription_id', $subscription->id)
            ->whereIn('entitlement_privilege_id', $feature->privileges()->select('id'))
            ->update(['deleted_at' => now()]);
    }

    private function addFeatureRemovalIfFeatureIsInPlan(Subscription $subscription, Feature $feature): void
    {
        $planId = $subscription->plan->parent_id ?? $subscription->plan->id;

        $inPlan = Entitlement::query()
            ->where('plan_id', $planId)
            ->where('entitlement_feature_id', $feature->id)
            ->exists();

        if (! $inPlan) {
            return;
        }

        // Rails: insert_all(..., unique_by: :idx_unique_feature_removal_per_
        // subscription) — idempotent against the partial unique index.
        SubscriptionFeatureRemoval::query()->firstOrCreate([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'entitlement_feature_id' => $feature->id,
        ]);
    }
}
