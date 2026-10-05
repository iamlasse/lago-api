<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/plans/:code/fixed_charges',
    'ledger:rest:POST:/api/v1/plans/:code/fixed_charges',
    'ledger:rest:GET:/api/v1/plans/:code/fixed_charges/:code',
    'ledger:rest:PUT:/api/v1/plans/:code/fixed_charges/:code',
    'ledger:rest:PATCH:/api/v1/plans/:code/fixed_charges/:code',
    'ledger:rest:DELETE:/api/v1/plans/:code/fixed_charges/:code',
);

use App\Models\Plan;
use App\Models\AddOn;
use App\Models\FixedCharge;
use App\Models\Organization;

/**
 * Port of Rails' spec/requests/api/v1/plans/fixed_charges_controller_spec.rb.
 *
 * Scenarios not ported (dependencies do not exist yet):
 * - cascade children-job assertions (FixedCharges::*ChildrenJob — cascade is
 *   a no-op in the services until child plans are a milestone);
 * - FixedChargeEvent emission on create/update with
 *   apply_units_immediately (billing is a later milestone).
 */
function fixedChargesOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

function fixedChargesBearer(array $ctx): array
{
    return ['Authorization' => 'Bearer '.$ctx[1]->value];
}

// -- GET /api/v1/plans/:plan_code/fixed_charges --------------------------------

it('returns a list of fixed charges', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();
    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'add_on_id' => $addOn->id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/fixed_charges', fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($fixedCharge): void {
            $json->count('fixed_charges', 1)
                ->where('fixed_charges.0.lago_id', (string) $fixedCharge->id)
                ->where('fixed_charges.0.code', $fixedCharge->code)
                ->etc();
        });
});

it('returns fixed charge pagination metadata', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    FixedCharge::factory()->count(3)->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'add_on_id' => AddOn::factory()->for($organization)->create()->id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/fixed_charges?per_page=2&page=1', fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('fixed_charges', 2)->etc()
                ->where('meta.current_page', 1)
                ->where('meta.total_pages', 2);
        });
});

it('returns not found error when plan does not exist on fixed charges index', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();

    test()->getJson('/api/v1/plans/invalid_code/fixed_charges', fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('only returns parent fixed charges', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $childPlan = Plan::factory()->create(['organization_id' => $organization->id, 'parent_id' => $plan->id]);
    $addOn = AddOn::factory()->for($organization)->create();
    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'add_on_id' => $addOn->id,
    ]);
    FixedCharge::factory()->create([
        'plan_id' => $childPlan->id, 'organization_id' => $organization->id, 'parent_id' => $fixedCharge->id,
        'add_on_id' => $fixedCharge->add_on_id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/fixed_charges', fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($fixedCharge): void {
            $json->count('fixed_charges', 1)
                ->where('fixed_charges.0.lago_id', (string) $fixedCharge->id)
                ->etc();
        });
});

// -- GET /api/v1/plans/:plan_code/fixed_charges/:code ---------------------------

it('returns the fixed charge', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();
    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'add_on_id' => $addOn->id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/fixed_charges/'.$fixedCharge->code, fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($fixedCharge): void {
            $json->where('fixed_charge.lago_id', (string) $fixedCharge->id)
                ->where('fixed_charge.code', $fixedCharge->code)
                ->where('fixed_charge.charge_model', 'standard')
                ->etc();
        });
});

it('returns not found error when plan does not exist on fixed charge show', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $fixedCharge = FixedCharge::factory()->create(['organization_id' => $organization->id]);

    test()->getJson('/api/v1/plans/invalid_code/fixed_charges/'.$fixedCharge->code, fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when fixed charge does not exist', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/fixed_charges/invalid_code', fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'fixed_charge_not_found']);
});

// -- POST /api/v1/plans/:plan_code/fixed_charges --------------------------------

it('creates a new fixed charge', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();

    $createParams = [
        'add_on_id' => $addOn->id,
        'code' => 'new_fixed_charge_code',
        'charge_model' => 'standard',
        'invoice_display_name' => 'Test Fixed Charge',
        'units' => 10,
        'properties' => ['amount' => '100'],
    ];

    test()->postJson('/api/v1/plans/'.$plan->code.'/fixed_charges', ['fixed_charge' => $createParams], fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($addOn): void {
            $json->where('fixed_charge.code', 'new_fixed_charge_code')
                ->where('fixed_charge.charge_model', 'standard')
                ->where('fixed_charge.invoice_display_name', 'Test Fixed Charge')
                ->where('fixed_charge.lago_add_on_id', (string) $addOn->id)
                ->where('fixed_charge.units', '10.0')
                ->etc();
        });

    expect($plan->fixedCharges()->count())->toBe(1);
});

it('creates a new fixed charge using add_on_code instead of add_on_id', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();

    $createParams = [
        'add_on_code' => $addOn->code,
        'code' => 'new_fixed_charge_code',
        'charge_model' => 'standard',
        'units' => 5,
        'properties' => ['amount' => '50'],
    ];

    test()->postJson('/api/v1/plans/'.$plan->code.'/fixed_charges', ['fixed_charge' => $createParams], fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJsonPath('fixed_charge.lago_add_on_id', (string) $addOn->id);
});

it('returns not found error when plan does not exist on fixed charge create', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $addOn = AddOn::factory()->for($organization)->create();

    test()->postJson('/api/v1/plans/invalid_code/fixed_charges', ['fixed_charge' => [
        'add_on_id' => $addOn->id,
        'code' => 'new_fixed_charge_code',
        'charge_model' => 'standard',
        'units' => 1,
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when add on does not exist', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->postJson('/api/v1/plans/'.$plan->code.'/fixed_charges', ['fixed_charge' => [
        'add_on_id' => 'invalid_id',
        'code' => 'new_fixed_charge_code',
        'charge_model' => 'standard',
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'add_on_not_found']);
});

it('creates a fixed charge with taxes', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();
    $tax = App\Models\Tax::factory()->for($organization)->create();

    $createParams = [
        'add_on_id' => $addOn->id,
        'code' => 'taxed_fixed_charge',
        'charge_model' => 'standard',
        'units' => 1,
        'properties' => ['amount' => '100'],
        'tax_codes' => [$tax->code],
    ];

    test()->postJson('/api/v1/plans/'.$plan->code.'/fixed_charges', ['fixed_charge' => $createParams], fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($tax): void {
            $json->count('fixed_charge.taxes', 1)
                ->where('fixed_charge.taxes.0.code', $tax->code)
                ->etc();
        });
});

it('accepts cascade_updates on fixed charge create', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();

    test()->postJson('/api/v1/plans/'.$plan->code.'/fixed_charges', ['fixed_charge' => [
        'add_on_id' => $addOn->id,
        'code' => 'cascaded_fixed_charge',
        'charge_model' => 'standard',
        'units' => 1,
        'properties' => ['amount' => '100'],
        'cascade_updates' => true,
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJsonPath('fixed_charge.code', 'cascaded_fixed_charge');
});

// -- PUT /api/v1/plans/:plan_code/fixed_charges/:code ---------------------------

it('updates the fixed charge', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();
    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'add_on_id' => $addOn->id,
    ]);

    test()->putJson('/api/v1/plans/'.$plan->code.'/fixed_charges/'.$fixedCharge->code, ['fixed_charge' => [
        'invoice_display_name' => 'Updated Fixed Charge Name',
        'charge_model' => 'standard',
        'units' => 20,
        'properties' => ['amount' => '200'],
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('fixed_charge.invoice_display_name', 'Updated Fixed Charge Name')
                ->where('fixed_charge.units', '20.0')
                ->where('fixed_charge.properties.amount', '200')
                ->etc();
        });
});

it('returns not found error when plan does not exist on fixed charge update', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $fixedCharge = FixedCharge::factory()->create(['organization_id' => $organization->id]);

    test()->putJson('/api/v1/plans/invalid_code/fixed_charges/'.$fixedCharge->code, ['fixed_charge' => [
        'units' => 25,
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when fixed charge does not exist on update', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->putJson('/api/v1/plans/'.$plan->code.'/fixed_charges/invalid_code', ['fixed_charge' => [
        'units' => 25,
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'fixed_charge_not_found']);
});

// -- PATCH /api/v1/plans/:plan_code/fixed_charges/:code -------------------------
// Rails maps PATCH to the same Plans::FixedChargesController#update the PUT
// route hits (plan_nested_api.rb resources :fixed_charges; no verb branch in
// the controller chain — pinned contractually by the plans_fixed_charges_patch
// scenario).

it('updates the fixed charge via PATCH', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();
    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'add_on_id' => $addOn->id,
    ]);

    test()->patchJson('/api/v1/plans/'.$plan->code.'/fixed_charges/'.$fixedCharge->code, ['fixed_charge' => [
        'invoice_display_name' => 'Updated Fixed Charge Name',
        'charge_model' => 'standard',
        'units' => 20,
        'properties' => ['amount' => '200'],
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('fixed_charge.invoice_display_name', 'Updated Fixed Charge Name')
                ->where('fixed_charge.units', '20.0')
                ->where('fixed_charge.properties.amount', '200')
                ->etc();
        });
});

it('returns not found error when plan does not exist on fixed charge PATCH', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $fixedCharge = FixedCharge::factory()->create(['organization_id' => $organization->id]);

    test()->patchJson('/api/v1/plans/invalid_code/fixed_charges/'.$fixedCharge->code, ['fixed_charge' => [
        'units' => 25,
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when fixed charge does not exist on PATCH', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->patchJson('/api/v1/plans/'.$plan->code.'/fixed_charges/invalid_code', ['fixed_charge' => [
        'units' => 25,
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'fixed_charge_not_found']);
});

// -- DELETE /api/v1/plans/:plan_code/fixed_charges/:code -------------------------

it('soft deletes the fixed charge', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();
    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'add_on_id' => $addOn->id,
    ]);

    test()->deleteJson('/api/v1/plans/'.$plan->code.'/fixed_charges/'.$fixedCharge->code, [], fixedChargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJsonPath('fixed_charge.lago_id', (string) $fixedCharge->id);

    expect($fixedCharge->fresh()->deleted_at)->not->toBeNull();
});

it('returns not found error when plan does not exist on fixed charge destroy', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $fixedCharge = FixedCharge::factory()->create(['organization_id' => $organization->id]);

    test()->deleteJson('/api/v1/plans/invalid_code/fixed_charges/'.$fixedCharge->code, [], fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when fixed charge does not exist on destroy', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->deleteJson('/api/v1/plans/'.$plan->code.'/fixed_charges/invalid_code', [], fixedChargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'fixed_charge_not_found']);
});

// -- legacy billing guard --------------------------------------------------------

it('forbids fixed charge writes with legacy_billing_disabled when product catalog is enabled', function (): void {
    [$organization, $apiKey] = fixedChargesOrganization();
    Illuminate\Support\Facades\DB::update(
        'update organizations set feature_flags = ARRAY[?]::varchar[] where id = ?',
        ['product_catalog', $organization->id],
    );
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $addOn = AddOn::factory()->for($organization)->create();

    test()->postJson('/api/v1/plans/'.$plan->code.'/fixed_charges', ['fixed_charge' => [
        'add_on_id' => $addOn->id,
        'code' => 'fc',
        'charge_model' => 'standard',
        'units' => 1,
    ]], fixedChargesBearer([$organization, $apiKey]))
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'legacy_billing_disabled',
        ]);
});
