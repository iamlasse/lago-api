<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\EntitlementValue;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Models\SubscriptionFeatureRemoval;
use App\Services\Failures\NotFoundFailure;
use App\Models\Entitlement\SubscriptionEntitlement;

/**
 * Port of Rails' Entitlement::SubscriptionEntitlementsUpdateService
 * (app/services/entitlement/subscription_entitlements_update_service.rb) —
 * the PATCH on a subscription's entitlements: full sync (partial: false)
 * removes features the plan granted but the params dropped (or deletes the
 * subscription override), additive merge (partial: true) only adds.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable
 *   "subscription.updated").
 * - TODO(port): SendWebhookJob.perform_after_commit("subscription.updated",
 *   subscription) — the webhook is sent even if no changes were made.
 */
class SubscriptionEntitlementsUpdateService extends BaseService
{
    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly array $entitlementsParams,
        private readonly bool $partial,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();
        $subscription = $this->subscription;

        if ($subscription === null) {
            return $result->notFoundFailure('subscription');
        }

        try {
            DB::transaction(function () use ($subscription, $result): void {
                if (! $this->partial) {
                    $this->removeOrDeleteMissingFeatures($subscription);
                }

                $this->updateEntitlements($subscription, $result);
            });

            // TODO(port): SendWebhookJob.perform_after_commit(
            //   "subscription.updated", subscription).

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `remove_or_delete_missing_features` — features currently
     * granted but absent from the params: a subscription override is
     * deleted, a plan grant gets a SubscriptionFeatureRemoval tombstone.
     */
    private function removeOrDeleteMissingFeatures(Subscription $subscription): void
    {
        $currentCodes = array_map(
            fn (SubscriptionEntitlement $entitlement): string => (string) $entitlement->code,
            SubscriptionEntitlement::forSubscription($subscription),
        );

        $missingCodes = array_values(array_unique(array_diff(
            $currentCodes,
            array_map('strval', array_keys($this->entitlementsParams)),
        )));

        // If the feature was added as a subscription override, delete it.
        $subEntitlements = Entitlement::query()
            ->where('subscription_id', $subscription->id)
            ->whereHas('feature', fn ($query) => $query->whereIn('code', $missingCodes));

        EntitlementValue::query()
            ->whereIn('entitlement_entitlement_id', (clone $subEntitlements)->select('id'))
            ->update(['deleted_at' => now()]);
        $subEntitlements->update(['deleted_at' => now()]);

        // If the feature is from the plan, create a SubscriptionFeatureRemoval.
        $plan = $subscription->plan->parent_id !== null ? $subscription->plan->parent : $subscription->plan;

        $planEntitlements = Entitlement::query()
            ->where('plan_id', $plan->id)
            ->whereHas('feature', fn ($query) => $query->whereIn('code', $missingCodes))
            ->with('feature')
            ->get();

        foreach ($planEntitlements as $entitlement) {
            SubscriptionFeatureRemoval::query()->create([
                'organization_id' => $subscription->organization_id,
                'subscription_id' => $subscription->id,
                'entitlement_feature_id' => $entitlement->feature->id,
            ]);
        }

        // If there was any privilege removal for a removed feature, we clean
        // them up.
        SubscriptionFeatureRemoval::query()->where('subscription_id', $subscription->id)
            ->whereIn(
                'entitlement_privilege_id',
                Privilege::query()
                    ->whereHas('feature', fn ($query) => $query->whereIn('code', $missingCodes))
                    ->select('id'),
            )
            ->update(['deleted_at' => now()]);
    }

    /**
     * Rails: `update_entitlements` — batch-loads the plan's and the
     * subscription's entitlements, then delegates each feature to the core
     * update service.
     */
    private function updateEntitlements(Subscription $subscription, BaseResult $result): void
    {
        if ($this->entitlementsParams === []) {
            return;
        }

        $plan = $subscription->plan->parent_id !== null ? $subscription->plan->parent : $subscription->plan;

        $featuresByCode = Feature::query()
            ->where('organization_id', $subscription->organization_id)
            ->with('privileges')
            ->whereIn('code', array_map('strval', array_keys($this->entitlementsParams)))
            ->get()
            ->keyBy('code');

        // NOTE: Some feature codes were not found.
        if ($featuresByCode->count() !== count($this->entitlementsParams)) {
            throw new NotFoundFailure($result, 'feature');
        }

        $featureIds = $featuresByCode->modelKeys();

        $planEntitlementsByFeatureId = Entitlement::query()
            ->where('plan_id', $plan->id)
            ->whereIn('entitlement_feature_id', $featureIds)
            ->with('values.privilege')
            ->get()
            ->keyBy('entitlement_feature_id');

        $subEntitlementsByFeatureId = Entitlement::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('entitlement_feature_id', $featureIds)
            ->with('values.privilege')
            ->get()
            ->keyBy('entitlement_feature_id');

        foreach ($this->entitlementsParams as $featureCode => $privilegeParams) {
            $feature = $featuresByCode[(string) $featureCode];

            SubscriptionEntitlementCoreUpdateService::callBang(
                subscription: $subscription,
                plan: $plan,
                feature: $feature,
                planEntitlement: $planEntitlementsByFeatureId[$feature->id] ?? null,
                subEntitlement: $subEntitlementsByFeatureId[$feature->id] ?? null,
                privilegeParams: is_array($privilegeParams) ? $privilegeParams : [],
                partial: $this->partial,
            );
        }
    }
}
