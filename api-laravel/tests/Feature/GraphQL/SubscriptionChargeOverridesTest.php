<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Plan;
use App\Models\Charge;
use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Models\BillableMetricFilter;
use App\Models\SubscriptionFixedChargeUnitsOverride;

/**
 * Ports of Rails' spec/graphql/mutations/subscriptions/{update_charge,
 * update_fixed_charge,create_charge_filter,update_charge_filter,
 * destroy_charge_filter}_spec.rb over the frozen SDL. The whole family is
 * premium-gated and drives the plan-override machinery.
 *
 * Ledger rows: gql:mutation:updateSubscriptionCharge,
 * gql:mutation:updateSubscriptionFixedCharge,
 * gql:mutation:createSubscriptionChargeFilter,
 * gql:mutation:updateSubscriptionChargeFilter,
 * gql:mutation:destroySubscriptionChargeFilter.
 */
function gqlSubscriptionOverridesSetup(bool $premium = true): array
{
    // Rails specs stub License.premium? — the port keys off the configured
    // license token.
    config(['lago.license' => $premium ? 'premium-license-token' : null]);

    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'interval' => 'monthly',
        'code' => 'override_plan',
    ]);

    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'seats',
        'aggregation_type' => 'count_agg',
    ]);

    $charge = Charge::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'billable_metric_id' => $metric->id,
        'code' => 'seats_charge',
        'charge_model' => 'standard',
        'properties' => ['amount' => '10'],
    ]);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'external_id' => 'sub_'.Str::uuid(),
    ]);

    return [$organization->refresh(), $user, $subscription, $charge];
}

const UPDATE_SUBSCRIPTION_CHARGE_MUTATION = <<<'GQL'
mutation($input: UpdateSubscriptionChargeInput!) {
    updateSubscriptionCharge(input: $input) { id properties { amount } }
}
GQL;

const UPDATE_SUBSCRIPTION_FIXED_CHARGE_MUTATION = <<<'GQL'
mutation($input: UpdateSubscriptionFixedChargeInput!) {
    updateSubscriptionFixedCharge(input: $input) { id units }
}
GQL;

const CREATE_SUBSCRIPTION_CHARGE_FILTER_MUTATION = <<<'GQL'
mutation($input: CreateSubscriptionChargeFilterInput!) {
    createSubscriptionChargeFilter(input: $input) { id invoiceDisplayName values }
}
GQL;

const UPDATE_SUBSCRIPTION_CHARGE_FILTER_MUTATION = <<<'GQL'
mutation($input: UpdateSubscriptionChargeFilterInput!) {
    updateSubscriptionChargeFilter(input: $input) { id invoiceDisplayName }
}
GQL;

const DESTROY_SUBSCRIPTION_CHARGE_FILTER_MUTATION = <<<'GQL'
mutation($input: DestroySubscriptionChargeFilterInput!) {
    destroySubscriptionChargeFilter(input: $input) { id }
}
GQL;

it('answers forbidden without the premium license', function (): void {
    [$organization, $user, $subscription, $charge] = gqlSubscriptionOverridesSetup(premium: false);

    gqlPost(UPDATE_SUBSCRIPTION_CHARGE_MUTATION, ['input' => [
        'subscriptionId' => $subscription->id,
        'chargeCode' => 'seats_charge',
        'properties' => ['amount' => '99'],
    ]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'feature_unavailable');
});

it('answers not_found for an unknown subscription or charge', function (): void {
    [$organization, $user, $subscription, $charge] = gqlSubscriptionOverridesSetup();

    gqlPost(UPDATE_SUBSCRIPTION_CHARGE_MUTATION, ['input' => [
        'subscriptionId' => Str::uuid(),
        'chargeCode' => 'seats_charge',
        'properties' => ['amount' => '99'],
    ]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    gqlPost(UPDATE_SUBSCRIPTION_CHARGE_MUTATION, ['input' => [
        'subscriptionId' => $subscription->id,
        'chargeCode' => 'missing_charge',
        'properties' => ['amount' => '99'],
    ]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');
});

it('overrides a subscription charge onto the override plan', function (): void {
    [$organization, $user, $subscription, $charge] = gqlSubscriptionOverridesSetup();

    $payload = gqlPost(UPDATE_SUBSCRIPTION_CHARGE_MUTATION, ['input' => [
        'subscriptionId' => $subscription->id,
        'chargeCode' => 'seats_charge',
        'properties' => ['amount' => '42'],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateSubscriptionCharge');

    expect($payload['id'])->not->toBeNull()
        ->and($payload['properties']['amount'])->toBe('42');

    // The subscription now points at the override plan, and the negotiated
    // charge is a copy of the catalog charge on that plan.
    $subscription->refresh();

    expect($subscription->plan->parent_id)->toBe($charge->plan_id)
        ->and($subscription->plan->charges()->count())->toBe(1);
});

it('writes a units override through updateSubscriptionFixedCharge', function (): void {
    [$organization, $user, $subscription, $charge] = gqlSubscriptionOverridesSetup();

    $fixedCharge = App\Models\FixedCharge::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $subscription->plan_id,
        'code' => 'setup',
        'charge_model' => 'standard',
        'units' => '5',
        'properties' => ['amount' => '100'],
    ]);

    $payload = gqlPost(UPDATE_SUBSCRIPTION_FIXED_CHARGE_MUTATION, ['input' => [
        'subscriptionId' => $subscription->id,
        'fixedChargeCode' => 'setup',
        'units' => '12',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateSubscriptionFixedCharge');

    // The endpoint answers the parent fixed charge — its own units stay,
    // the negotiated units live in the subscription's override row.
    expect($payload['units'])->toBe('5.0000000000');

    // The dedicated endpoint writes a units override row on the parent
    // fixed charge (the plan stays shared).
    $override = SubscriptionFixedChargeUnitsOverride::query()
        ->where('subscription_id', $subscription->id)
        ->first();

    expect($override)->not->toBeNull()
        ->and((float) $override->units)->toBe(12.0);

    expect($subscription->refresh()->plan->parent_id)->toBeNull();
});

it('creates, updates and destroys subscription charge filters', function (): void {
    [$organization, $user, $subscription, $charge] = gqlSubscriptionOverridesSetup();

    $metric = $charge->billableMetric;

    BillableMetricFilter::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'key' => 'region',
        'values' => ['eu', 'us'],
    ]);

    $created = gqlPost(CREATE_SUBSCRIPTION_CHARGE_FILTER_MUTATION, ['input' => [
        'subscriptionId' => $subscription->id,
        'chargeCode' => 'seats_charge',
        'invoiceDisplayName' => 'EU seats',
        'properties' => ['amount' => '7'],
        'values' => ['region' => ['eu']],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createSubscriptionChargeFilter');

    expect($created['invoiceDisplayName'])->toBe('EU seats');

    $filterId = $created['id'];

    // A second create with the same values answers value_already_exists.
    gqlPost(CREATE_SUBSCRIPTION_CHARGE_FILTER_MUTATION, ['input' => [
        'subscriptionId' => $subscription->id,
        'chargeCode' => 'seats_charge',
        'properties' => ['amount' => '7'],
        'values' => ['region' => ['eu']],
    ]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'unprocessable_entity');

    expect(gqlPost(UPDATE_SUBSCRIPTION_CHARGE_FILTER_MUTATION, ['input' => [
        'subscriptionId' => $subscription->id,
        'chargeCode' => 'seats_charge',
        'invoiceDisplayName' => 'EU seats renamed',
        'values' => ['region' => ['eu']],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateSubscriptionChargeFilter.invoiceDisplayName'))
        ->toBe('EU seats renamed');

    expect(gqlPost(DESTROY_SUBSCRIPTION_CHARGE_FILTER_MUTATION, ['input' => [
        'subscriptionId' => $subscription->id,
        'chargeCode' => 'seats_charge',
        'values' => ['region' => ['eu']],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.destroySubscriptionChargeFilter.id'))
        ->toBe($filterId);
});
