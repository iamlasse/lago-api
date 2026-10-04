<?php

declare(strict_types=1);

namespace App\Models\Entitlement;

use App\Queries\SubscriptionEntitlementQuery;

/**
 * Port of Rails' Entitlement::SubscriptionEntitlement
 * (app/models/entitlement/subscription_entitlement.rb) — an ActiveModel
 * projection (not a table row): the merged view of a feature's plan
 * entitlement and subscription override, as served by the subscription
 * entitlements endpoints and GraphQL.
 */
class SubscriptionEntitlement
{
    /**
     * @param  list<SubscriptionEntitlementPrivilege>|null  $privileges
     */
    public function __construct(
        public ?string $organizationId = null,
        public ?string $entitlementFeatureId = null,
        public ?string $code = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $planEntitlementId = null,
        public ?string $subEntitlementId = null,
        public ?string $planId = null,
        public ?string $subscriptionId = null,
        public ?string $orderingDate = null,
        public ?array $privileges = null,
    ) {}

    /** Rails: `self.for_subscription(subscription)`. */
    public static function forSubscription(\App\Models\Subscription $subscription): array
    {
        return SubscriptionEntitlementQuery::call(
            organization: $subscription->organization,
            filters: [
                'subscription_id' => (string) $subscription->id,
                'plan_id' => (string) (($subscription->plan->parent_id) ?: $subscription->plan->id),
            ],
        )->entitlements;
    }

    /**
     * Rails: `to_h` — attributes hash with the privileges indexed by code.
     *
     * @return array<string, mixed>
     */
    public function toH(): array
    {
        $privileges = [];

        foreach ($this->privileges ?? [] as $privilege) {
            $privileges[$privilege->code] = $privilege->toH();
        }

        return [
            'organization_id' => $this->organizationId,
            'entitlement_feature_id' => $this->entitlementFeatureId,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'plan_entitlement_id' => $this->planEntitlementId,
            'sub_entitlement_id' => $this->subEntitlementId,
            'plan_id' => $this->planId,
            'subscription_id' => $this->subscriptionId,
            'ordering_date' => $this->orderingDate,
            'privileges' => $privileges,
        ];
    }
}
