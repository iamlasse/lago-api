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
use App\Services\Failures\NotFoundFailure;
use App\Models\Entitlement\SubscriptionEntitlement;

/**
 * Port of Rails' Entitlement::SubscriptionEntitlementUpdateService
 * (app/services/entitlement/subscription_entitlement_update_service.rb) —
 * updates a single feature's entitlement on a subscription (the GraphQL
 * createOrUpdateSubscriptionEntitlement mutation's path).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable
 *   "subscription.updated").
 * - TODO(port): SendWebhookJob.perform_after_commit("subscription.updated",
 *   subscription) — the webhook is sent even if no changes were made.
 */
class SubscriptionEntitlementUpdateService extends BaseService
{
    public function __construct(
        private readonly ?Subscription $subscription,
        private readonly string $featureCode,
        private readonly array $privilegeParams,
        private readonly bool $partial,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('entitlement');
        $subscription = $this->subscription;

        if ($subscription === null) {
            return $result->notFoundFailure('subscription');
        }

        try {
            DB::transaction(function () use ($subscription, $result): void {
                $plan = $subscription->plan->parent_id !== null ? $subscription->plan->parent : $subscription->plan;

                /** @var Feature|null $feature */
                $feature = Feature::query()
                    ->where('organization_id', $subscription->organization_id)
                    ->with('privileges')
                    ->where('code', $this->featureCode)
                    ->first();

                if ($feature === null) {
                    throw new NotFoundFailure($result, 'feature');
                }

                SubscriptionEntitlementCoreUpdateService::callBang(
                    subscription: $subscription,
                    plan: $plan,
                    feature: $feature,
                    planEntitlement: Entitlement::query()
                        ->where('plan_id', $plan->id)
                        ->with('values.privilege')
                        ->where('entitlement_feature_id', $feature->id)
                        ->first(),
                    subEntitlement: Entitlement::query()
                        ->where('subscription_id', $subscription->id)
                        ->with('values.privilege')
                        ->where('entitlement_feature_id', $feature->id)
                        ->first(),
                    privilegeParams: $this->privilegeParams,
                    partial: $this->partial,
                );
            });

            // TODO(port): SendWebhookJob.perform_after_commit(
            //   "subscription.updated", subscription).

            // Rails: result.entitlement = SubscriptionEntitlement
            //   .for_subscription(subscription).find { it.code == feature_code }
            $result->entitlement = $this->findEntitlement($subscription);

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    private function findEntitlement(Subscription $subscription): ?SubscriptionEntitlement
    {
        foreach (SubscriptionEntitlement::forSubscription($subscription) as $entitlement) {
            if ($entitlement->code === $this->featureCode) {
                return $entitlement;
            }
        }

        return null;
    }
}
