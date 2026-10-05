<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Commitment;
use App\Models\FixedCharge;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\Plans\OverrideService;
use App\Services\Subscriptions\CreateService as SubscriptionCreateService;

/**
 * Port of spec/services/plans/override_service_spec.rb (core scenarios).
 */
beforeEach(function (): void {
    config(['lago.license' => 'premium-license-token']);
});

function overrideServiceOrganization(array $attributes = []): Organization
{
    return Organization::factory()->create($attributes);
}

function overriddenPlan(Organization $organization): Plan
{
    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
        'name' => 'Catalog',
    ]);

    Charge::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'invoice_display_name' => 'Catalog charge',
        'properties' => ['amount' => '10'],
    ]);

    FixedCharge::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'invoice_display_name' => 'Catalog fixed',
        'units' => 1,
    ]);

    return $plan;
}

it('refuses to override without a premium license', function (): void {
    config(['lago.license' => null]);

    $organization = overrideServiceOrganization();
    $plan = overriddenPlan($organization);

    $result = OverrideService::call(plan: $plan, params: ['amount_cents' => 100]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('feature_unavailable');
});

it('refuses a product-catalog organization', function (): void {
    $organization = overrideServiceOrganization(['feature_flags' => ['product_catalog']]);
    $plan = overriddenPlan($organization);

    $result = OverrideService::call(plan: $plan, params: ['amount_cents' => 100]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['plan_overrides' => ['legacy_billing_disabled']]);
});

it('creates a child plan overriding the negotiated fields only', function (): void {
    $organization = overrideServiceOrganization();
    $plan = overriddenPlan($organization);

    $result = OverrideService::callBang(plan: $plan, params: [
        'amount_cents' => 9900,
        'name' => 'Negotiated',
    ]);

    $child = $result->plan;
    $child->refresh();

    expect($child->parent_id)->toBe($plan->id)
        ->and($child->amount_cents)->toBe(9900)
        ->and($child->name)->toBe('Negotiated')
        ->and($child->amount_currency)->toBe('EUR')
        ->and($child->code)->toBe($plan->code)
        ->and($child->id)->not->toBe($plan->id);

    // The catalog plan is untouched.
    $plan->refresh();
    expect($plan->amount_cents)->toBe(4900)
        ->and($plan->name)->toBe('Catalog');

    // The charges and fixed charges are duplicated onto the child.
    expect($plan->charges()->count())->toBe(1)
        ->and($child->charges()->count())->toBe(1)
        ->and($child->charges()->first()->parent_id)->toBe($plan->charges()->first()->id)
        ->and($child->charges()->first()->invoice_display_name)->toBe('Catalog charge')
        ->and($child->fixedCharges()->count())->toBe(1)
        ->and($child->fixedCharges()->first()->parent_id)->toBe($plan->fixedCharges()->first()->id);
});

it('overrides the matched charge and fixed charge only', function (): void {
    $organization = overrideServiceOrganization();
    $plan = overriddenPlan($organization);

    $charge = $plan->charges()->first();
    $fixedCharge = $plan->fixedCharges()->first();

    $result = OverrideService::callBang(plan: $plan, params: [
        'charges' => [
            ['id' => $charge->id, 'invoice_display_name' => 'Negotiated charge', 'properties' => ['amount' => '42']],
        ],
        'fixed_charges' => [
            ['id' => $fixedCharge->id, 'units' => 5],
        ],
    ]);

    $child = $result->plan;

    $childCharge = $child->charges()->first();
    expect($childCharge->invoice_display_name)->toBe('Negotiated charge')
        ->and($childCharge->properties)->toBe(['amount' => '42'])
        ->and($childCharge->charge_model)->toBe($charge->charge_model);

    $childFixed = $child->fixedCharges()->first();
    expect((float) $childFixed->units)->toBe(5.0)
        ->and($childFixed->invoice_display_name)->toBe('Catalog fixed');
});

it('seeds the usage thresholds of the override plan', function (): void {
    $organization = overrideServiceOrganization(['premium_integrations' => ['progressive_billing']]);
    $plan = overriddenPlan($organization);

    $result = OverrideService::callBang(plan: $plan, params: [
        'usage_thresholds' => [
            ['amount_cents' => 5000, 'recurring' => true, 'threshold_display_name' => 'Threshold'],
        ],
    ]);

    $thresholds = $result->plan->usageThresholds()->get();

    expect($thresholds)->toHaveCount(1)
        ->and($thresholds[0]->amount_cents)->toBe(5000)
        ->and($thresholds[0]->recurring)->toBeTrue();
});

it('creates a fresh minimum commitment on the override plan', function (): void {
    $organization = overrideServiceOrganization();
    $plan = overriddenPlan($organization);

    $commitment = Commitment::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'amount_cents' => 10000,
    ]);

    $result = OverrideService::callBang(plan: $plan, params: [
        'minimum_commitment' => ['amount_cents' => 20000, 'invoice_display_name' => 'Negotiated floor'],
    ]);

    $childCommitment = $result->plan->minimumCommitment()->first();

    expect($childCommitment)->not->toBeNull()
        ->and($childCommitment->id)->not->toBe($commitment->id)
        ->and($childCommitment->amount_cents)->toBe(20000)
        ->and($childCommitment->invoice_display_name)->toBe('Negotiated floor');
});

it('creates the subscription on the override plan when plan_overrides ride the request', function (): void {
    $organization = overrideServiceOrganization();
    $plan = overriddenPlan($organization);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $subscription = SubscriptionCreateService::callBang(
        customer: $customer,
        plan: $plan,
        params: [
            'external_customer_id' => $customer->external_id,
            'external_id' => 'sub_over',
            'plan_overrides' => ['amount_cents' => 1234],
        ],
    )->subscription;

    $subscription->refresh();

    expect($subscription->plan_id)->not->toBe($plan->id)
        ->and($subscription->plan->parent_id)->toBe($plan->id)
        ->and($subscription->plan->amount_cents)->toBe(1234)
        // A same-day subscription with no activation rules activates.
        ->and($subscription->active())->toBeTrue();
});

it('keeps the catalog plan for a units-only plan_overrides change', function (): void {
    $organization = overrideServiceOrganization();
    $plan = overriddenPlan($organization);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $fixedCharge = $plan->fixedCharges()->first();

    $subscription = SubscriptionCreateService::callBang(
        customer: $customer,
        plan: $plan,
        params: [
            'external_customer_id' => $customer->external_id,
            'external_id' => 'sub_units',
            // TODO(port) upstream: the units write itself is the
            // fixed-charge-units-override slice; here we pin the dispatch —
            // no child plan is created for a units-only override.
            'plan_overrides' => [
                'fixed_charges' => [['id' => $fixedCharge->id, 'units' => 7]],
            ],
        ],
    )->subscription;

    $subscription->refresh();

    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->plan->parent_id)->toBeNull();
});
