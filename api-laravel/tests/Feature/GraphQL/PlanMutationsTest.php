<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Plan;
use Illuminate\Support\Str;

/**
 * Ports of Rails' spec/graphql/mutations/plans/{update,destroy}_spec.rb over
 * the frozen SDL (createPlan is covered elsewhere; updatePricingUnit landed
 * with the pricing units slice).
 *
 * Ledger rows: gql:mutation:updatePlan, gql:mutation:destroyPlan.
 */
function gqlPlanMutationsSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const UPDATE_PLAN_MUTATION = <<<'GQL'
mutation($input: UpdatePlanInput!) {
    updatePlan(input: $input) { id code name amountCents }
}
GQL;

const DESTROY_PLAN_MUTATION = <<<'GQL'
mutation($input: DestroyPlanInput!) {
    destroyPlan(input: $input) { id }
}
GQL;

it('updates a plan through updatePlan', function (): void {
    [$organization, $user] = gqlPlanMutationsSetup();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Basic plan',
        'code' => 'basic_plan',
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'interval' => 'monthly',
    ]);

    $payload = gqlPost(UPDATE_PLAN_MUTATION, ['input' => [
        'id' => $plan->id,
        'name' => 'Renamed plan',
        'code' => 'basic_plan',
        'amountCents' => 2000,
        'amountCurrency' => 'EUR',
        'interval' => 'monthly',
        'payInAdvance' => false,
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updatePlan');

    expect($payload['name'])->toBe('Renamed plan')
        ->and($payload['amountCents'])->toBe('2000');
});

it('answers validation errors from updatePlan with the error envelope', function (): void {
    [$organization, $user] = gqlPlanMutationsSetup();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'basic_plan',
    ]);

    // An unknown plan id answers not_found.
    gqlPost(UPDATE_PLAN_MUTATION, ['input' => [
        'id' => Str::uuid(),
        'name' => 'Nope',
        'code' => 'nope',
        'amountCents' => 100,
        'amountCurrency' => 'EUR',
        'interval' => 'monthly',
        'payInAdvance' => false,
    ]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    // A taken code answers the unprocessable_entity envelope.
    Plan::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'other_plan',
    ]);

    gqlPost(UPDATE_PLAN_MUTATION, ['input' => [
        'id' => $plan->id,
        'name' => 'Basic plan',
        'code' => 'other_plan',
        'amountCents' => 1000,
        'amountCurrency' => 'EUR',
        'interval' => 'monthly',
        'payInAdvance' => false,
    ]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'unprocessable_entity');
});

it('destroys a plan through destroyPlan', function (): void {
    [$organization, $user] = gqlPlanMutationsSetup();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'doomed_plan',
    ]);

    expect(gqlPost(DESTROY_PLAN_MUTATION, ['input' => ['id' => $plan->id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyPlan.id'))->toBe($plan->id);

    expect($plan->refresh()->trashed())->toBeTrue();
});
