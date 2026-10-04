<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Subscription;
use App\Models\SubscriptionFeatureRemoval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :subscription_feature_removal factories
 * (spec/factories/entitlement/subscription_feature_removals.rb) — the
 * tombstone rows: exactly one of feature / privilege (the schema CHECK
 * enforces it).
 *
 * @extends Factory<SubscriptionFeatureRemoval>
 */
class SubscriptionFeatureRemovalFactory extends Factory
{
    protected $model = SubscriptionFeatureRemoval::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'subscription_id' => SubscriptionFactory::new(),
            'entitlement_feature_id' => FeatureFactory::new(),
        ];
    }

    /** Keep organization_id consistent with the subscription. */
    public function configure(): static
    {
        return $this->afterCreating(function (SubscriptionFeatureRemoval $removal): void {
            $subscription = $removal->subscription;

            if ($subscription !== null && $removal->organization_id !== $subscription->organization_id) {
                $removal->forceFill(['organization_id' => $subscription->organization_id])->save();
            }
        });
    }

    /** A feature removal on an existing subscription. */
    public function forSubscriptionAndFeature(Subscription $subscription, Feature $feature): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'entitlement_feature_id' => $feature->id,
            'entitlement_privilege_id' => null,
        ]);
    }

    /** A single-privilege removal on an existing subscription. */
    public function forSubscriptionAndPrivilege(Subscription $subscription, Privilege $privilege): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'entitlement_feature_id' => null,
            'entitlement_privilege_id' => $privilege->id,
        ]);
    }
}
