<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\BillableMetric;
use App\Models\BillableMetricFilter;
use App\Models\Charge;
use App\Models\ChargeFilter;
use App\Models\FixedCharge;
use App\Models\Plan;
use Illuminate\Support\Str;

/**
 * Ports of Rails' spec/graphql/mutations/charges/*_spec.rb,
 * charge_filters/*_spec.rb and fixed_charges/*_spec.rb over the frozen SDL
 * (the REST controllers cover the services in depth).
 *
 * Ledger rows: gql:mutation:createCharge, gql:mutation:updateCharge,
 * gql:mutation:destroyCharge, gql:mutation:createChargeFilter,
 * gql:mutation:updateChargeFilter, gql:mutation:destroyChargeFilter,
 * gql:mutation:createFixedCharge, gql:mutation:updateFixedCharge,
 * gql:mutation:destroyFixedCharge.
 */
function gqlChargesSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    return [$organization->refresh(), $user, $plan];
}

const CREATE_CHARGE_MUTATION = <<<'GQL'
mutation($input: ChargeCreateInput!) {
    createCharge(input: $input) { id chargeModel invoiceable }
}
GQL;

const UPDATE_CHARGE_MUTATION = <<<'GQL'
mutation($input: ChargeUpdateInput!) {
    updateCharge(input: $input) { id invoiceDisplayName }
}
GQL;

const DESTROY_CHARGE_MUTATION = <<<'GQL'
mutation($input: DestroyChargeInput!) {
    destroyCharge(input: $input) { id }
}
GQL;

const CREATE_CHARGE_FILTER_MUTATION = <<<'GQL'
mutation($input: ChargeFilterCreateInput!) {
    createChargeFilter(input: $input) { id invoiceDisplayName values }
}
GQL;

const UPDATE_CHARGE_FILTER_MUTATION = <<<'GQL'
mutation($input: ChargeFilterUpdateInput!) {
    updateChargeFilter(input: $input) { id invoiceDisplayName }
}
GQL;

const DESTROY_CHARGE_FILTER_MUTATION = <<<'GQL'
mutation($input: DestroyChargeFilterInput!) {
    destroyChargeFilter(input: $input) { id }
}
GQL;

const CREATE_FIXED_CHARGE_MUTATION = <<<'GQL'
mutation($input: FixedChargeCreateInput!) {
    createFixedCharge(input: $input) { id chargeModel units }
}
GQL;

const UPDATE_FIXED_CHARGE_MUTATION = <<<'GQL'
mutation($input: FixedChargeUpdateInput!) {
    updateFixedCharge(input: $input) { id invoiceDisplayName }
}
GQL;

const DESTROY_FIXED_CHARGE_MUTATION = <<<'GQL'
mutation($input: DestroyFixedChargeInput!) {
    destroyFixedCharge(input: $input) { id }
}
GQL;

function gqlStandardChargeInput(string $planId, string $metricId): array
{
    return [
        'planId' => $planId,
        'billableMetricId' => $metricId,
        'code' => $code,
        'chargeModel' => 'standard',
        'invoiceable' => true,
        'properties' => ['amount' => '10'],
    ];
}

it('creates, updates and destroys a charge', function (): void {
    [$organization, $user, $plan] = gqlChargesSetup();

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $id = gqlPost(CREATE_CHARGE_MUTATION, ['input' => gqlStandardChargeInput($plan->id, $metric->id)], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.createCharge.id');

    expect($id)->not->toBeNull();

    // An unknown plan id answers the not_found error envelope.
    gqlPost(CREATE_CHARGE_MUTATION, ['input' => gqlStandardChargeInput(Str::uuid(), $metric->id)], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    expect(gqlPost(UPDATE_CHARGE_MUTATION, ['input' => [
        'id' => $id,
        'invoiceDisplayName' => 'Displayed charge',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateCharge.invoiceDisplayName'))
        ->toBe('Displayed charge');

    expect(gqlPost(DESTROY_CHARGE_MUTATION, ['input' => ['id' => $id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyCharge.id'))->toBe($id);
});

it('creates, updates and destroys a charge filter', function (): void {
    [$organization, $user, $plan] = gqlChargesSetup();

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    BillableMetricFilter::factory()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'key' => 'region',
        'values' => ['eu', 'us'],
    ]);

    $chargeId = gqlPost(CREATE_CHARGE_MUTATION, ['input' => gqlStandardChargeInput($plan->id, $metric->id)], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.createCharge.id');

    $payload = gqlPost(CREATE_CHARGE_FILTER_MUTATION, ['input' => [
        'chargeId' => $chargeId,
        'invoiceDisplayName' => 'EU filter',
        'properties' => ['amount' => '99'],
        'values' => ['region' => ['eu']],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createChargeFilter');

    expect($payload['invoiceDisplayName'])->toBe('EU filter');

    $filterId = $payload['id'];

    // An empty values object answers the value_is_mandatory envelope.
    gqlPost(CREATE_CHARGE_FILTER_MUTATION, ['input' => [
        'chargeId' => $chargeId,
        'properties' => ['amount' => '99'],
        'values' => [],
    ]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'unprocessable_entity');

    expect(gqlPost(UPDATE_CHARGE_FILTER_MUTATION, ['input' => [
        'id' => $filterId,
        'invoiceDisplayName' => 'Renamed filter',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateChargeFilter.invoiceDisplayName'))
        ->toBe('Renamed filter');

    expect(gqlPost(DESTROY_CHARGE_FILTER_MUTATION, ['input' => ['id' => $filterId]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyChargeFilter.id'))->toBe($filterId);

    expect(ChargeFilter::withTrashed()->find($filterId)->trashed())->toBeTrue();
});

it('creates, updates and destroys a fixed charge', function (): void {
    [$organization, $user, $plan] = gqlChargesSetup();

    $addOnId = null;

    $id = gqlPost(CREATE_FIXED_CHARGE_MUTATION, ['input' => [
        'planId' => $plan->id,
        'addOnId' => \App\Models\AddOn::factory()->create(['organization_id' => $organization->id])->id,
        'chargeModel' => 'standard',
        'units' => '10',
        'properties' => ['amount' => '100'],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createFixedCharge.id');

    expect($id)->not->toBeNull();

    expect(gqlPost(UPDATE_FIXED_CHARGE_MUTATION, ['input' => [
        'id' => $id,
        'invoiceDisplayName' => 'Setup fee',
        'units' => '20',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateFixedCharge.invoiceDisplayName'))
        ->toBe('Setup fee');

    expect(gqlPost(DESTROY_FIXED_CHARGE_MUTATION, ['input' => ['id' => $id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyFixedCharge.id'))->toBe($id);
});
