<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Entitlement;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :entitlement factories
 * (spec/factories/entitlement/entitlements.rb) — plan_entitlement and
 * subscription_entitlement. The catalog-plan variant has no equivalent
 * (catalog plans are not part of the legacy-engine port).
 *
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    protected $model = Entitlement::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'entitlement_feature_id' => FeatureFactory::new(),
            'plan_id' => PlanFactory::new(),
        ];
    }

    /** Keep organization_id consistent with the parent / feature. */
    public function configure(): static
    {
        return $this->afterCreating(function (Entitlement $entitlement): void {
            $parentId = $entitlement->subscription_id !== null
                ? $entitlement->subscription()->value('organization_id')
                : ($entitlement->plan_id !== null ? $entitlement->plan()->value('organization_id') : null);

            $organizationId = (string) ($parentId
                ?? $entitlement->feature?->organization_id
                ?? $entitlement->organization_id);

            if ($entitlement->organization_id !== $organizationId) {
                $entitlement->forceFill(['organization_id' => $organizationId])->save();
            }
        });
    }

    /** Rails factory :plan_entitlement — the default. */
    public function forPlan(Plan $plan): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $plan->organization_id,
            'plan_id' => $plan->id,
        ]);
    }

    /** Rails factory :subscription_entitlement. */
    public function forSubscription(Subscription $subscription): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'plan_id' => null,
        ]);
    }

    /** Bind a specific feature. */
    public function forFeature(Feature $feature): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $feature->organization_id,
            'entitlement_feature_id' => $feature->id,
        ]);
    }

    /** Rails trait :discarded. */
    public function discarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
