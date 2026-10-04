<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Models\SubscriptionFeatureRemoval;

/**
 * Port of Rails' Entitlement::SubscriptionFeaturePrivilegeRemoveService
 * (app/services/entitlement/subscription_feature_privilege_remove_service.rb)
 * — the DELETE on a subscription entitlement privilege: the override value
 * is discarded and, when the privilege is inherited from the plan, a
 * removal tombstone is recorded.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable
 *   "subscription.updated").
 * - TODO(port): SendWebhookJob.perform_after_commit("subscription.updated",
 *   subscription).
 */
class SubscriptionFeaturePrivilegeRemoveService extends BaseService
{
    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly string $featureCode,
        private readonly string $privilegeCode,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('feature_code', 'privilege_code');
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

        /** @var Privilege|null $privilege */
        $privilege = Privilege::query()
            ->where('organization_id', $subscription->organization_id)
            ->where('entitlement_feature_id', $feature->id)
            ->where('code', $this->privilegeCode)
            ->first();

        if ($privilege === null) {
            return $result->notFoundFailure('privilege');
        }

        try {
            DB::transaction(function () use ($subscription, $feature, $privilege): void {
                $this->deleteSubscriptionEntitlementValueIfExists($subscription, $feature, $privilege);
                $this->addPrivilegeRemovalIfPrivilegeIsInPlan($subscription, $feature, $privilege);
            });

            // TODO(port): SendWebhookJob.perform_after_commit(
            //   "subscription.updated", subscription).

            $result->featureCode = $this->featureCode;
            $result->privilegeCode = $this->privilegeCode;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    private function deleteSubscriptionEntitlementValueIfExists(
        Subscription $subscription,
        Feature $feature,
        Privilege $privilege,
    ): void {
        $entitlement = Entitlement::query()->where('subscription_id', $subscription->id)->where('entitlement_feature_id', $feature->id)->first();

        if ($entitlement === null) {
            return;
        }

        $entitlement->values()
            ->where('entitlement_privilege_id', $privilege->id)
            ->update(['deleted_at' => now()]);
    }

    private function addPrivilegeRemovalIfPrivilegeIsInPlan(
        Subscription $subscription,
        Feature $feature,
        Privilege $privilege,
    ): void {
        $planId = $subscription->plan->parent_id ?? $subscription->plan->id;

        $inPlan = Entitlement::query()
            ->where('plan_id', $planId)
            ->where('entitlement_feature_id', $feature->id)
            ->exists();

        if (! $inPlan) {
            return;
        }

        // Rails: insert_all(..., unique_by: :idx_unique_privilege_removal_
        // per_subscription) — idempotent against the partial unique index.
        SubscriptionFeatureRemoval::query()->firstOrCreate([
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'entitlement_privilege_id' => $privilege->id,
        ]);
    }
}
