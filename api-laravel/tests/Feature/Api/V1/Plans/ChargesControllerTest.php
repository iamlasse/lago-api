<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/plans/:code/charges',
    'ledger:rest:POST:/api/v1/plans/:code/charges',
    'ledger:rest:GET:/api/v1/plans/:code/charges/:code',
    'ledger:rest:PUT:/api/v1/plans/:code/charges/:code',
    'ledger:rest:DELETE:/api/v1/plans/:code/charges/:code',
    'ledger:rest:GET:/api/v1/plans/:code/charges/:code/filters',
    'ledger:rest:GET:/api/v1/plans/:code/charges/:code/filters/:id',
);

use App\Models\Plan;
use App\Models\Charge;
use Illuminate\Support\Str;
use App\Models\Organization;

/**
 * Port of Rails' spec/requests/api/v1/plans/charges_controller_spec.rb
 * (+ the filters controller's index/show scenarios).
 *
 * Scenarios not ported (dependencies do not exist yet):
 * - cascade children-job assertions (Charges::*ChildrenJob — cascade is a
 *   no-op in the services until child plans are a milestone);
 * - accepts_target_wallet premium flag (events store + wallets);
 * - applied_pricing_unit (premium pricing units);
 * - presentation_group_keys (premium catalog feature);
 * - filter create/update/destroy (ChargeFilters::Create/Update/DestroyService
 *   not ported — only index/show need no service).
 */
function chargesOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

function chargesBearer(array $ctx): array
{
    return ['Authorization' => 'Bearer '.$ctx[1]->value];
}

// -- GET /api/v1/plans/:plan_code/charges --------------------------------------

it('returns a list of charges', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = App\Models\BillableMetric::factory()->for($organization)->create();
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges', chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($charge) {
            $json->count('charges', 1)
                ->where('charges.0.lago_id', (string) $charge->id)
                ->where('charges.0.code', $charge->code)
                ->etc();
        });
});

it('returns charge pagination metadata', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    Charge::factory()->count(3)->create([
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges?per_page=2&page=1', chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('charges', 2)->etc()
                ->where('meta.current_page', 1)
                ->where('meta.total_pages', 2);
        });
});

it('returns not found error when plan does not exist on charges index', function (): void {
    [$organization, $apiKey] = chargesOrganization();

    test()->getJson('/api/v1/plans/invalid_code/charges', chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('only returns parent charges', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $childPlan = Plan::factory()->create(['organization_id' => $organization->id, 'parent_id' => $plan->id]);
    $metric = App\Models\BillableMetric::factory()->for($organization)->create();
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'billable_metric_id' => $metric->id,
    ]);
    Charge::factory()->standard()->create([
        'plan_id' => $childPlan->id, 'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id, 'parent_id' => $charge->id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges', chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($charge) {
            $json->count('charges', 1)
                ->where('charges.0.lago_id', (string) $charge->id)
                ->etc();
        });
});

// -- GET /api/v1/plans/:plan_code/charges/:code --------------------------------

it('returns the charge', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = App\Models\BillableMetric::factory()->for($organization)->create();
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'billable_metric_id' => $metric->id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges/'.$charge->code, chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($charge, $metric) {
            $json->where('charge.lago_id', (string) $charge->id)
                ->where('charge.code', $charge->code)
                ->where('charge.charge_model', 'standard')
                ->where('charge.lago_billable_metric_id', (string) $metric->id)
                ->etc();
        });
});

it('returns not found error when plan does not exist on charge show', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $charge = Charge::factory()->standard()->create();

    test()->getJson('/api/v1/plans/invalid_code/charges/'.$charge->code, chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when charge does not exist', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges/invalid_code', chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'charge_not_found']);
});

// -- POST /api/v1/plans/:plan_code/charges -------------------------------------

it('creates a new charge', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = App\Models\BillableMetric::factory()->for($organization)->create();

    $createParams = [
        'billable_metric_id' => $metric->id,
        'code' => 'new_charge_code',
        'charge_model' => 'standard',
        'invoice_display_name' => 'Test Charge',
        'properties' => ['amount' => '100'],
    ];

    test()->postJson('/api/v1/plans/'.$plan->code.'/charges', ['charge' => $createParams], chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($metric) {
            $json->where('charge.code', 'new_charge_code')
                ->where('charge.charge_model', 'standard')
                ->where('charge.invoice_display_name', 'Test Charge')
                ->where('charge.lago_billable_metric_id', (string) $metric->id)
                ->where('charge.properties.amount', '100')
                ->etc();
        });

    expect($plan->charges()->count())->toBe(1);
});

it('returns not found error when plan does not exist on charge create', function (): void {
    [$organization, $apiKey] = chargesOrganization();

    test()->postJson('/api/v1/plans/invalid_code/charges', ['charge' => [
        'billable_metric_id' => Str::uuid(),
        'code' => 'new_charge_code',
        'charge_model' => 'standard',
    ]], chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when billable metric does not exist on charge create', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->postJson('/api/v1/plans/'.$plan->code.'/charges', ['charge' => [
        'billable_metric_id' => 'invalid_id',
        'code' => 'new_charge_code',
        'charge_model' => 'standard',
    ]], chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'billable_metric_not_found']);
});

it('creates a charge with filters', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = App\Models\BillableMetric::factory()->for($organization)->create();
    $metricFilter = App\Models\BillableMetricFilter::factory()->create(['billable_metric_id' => $metric->id, 'organization_id' => $organization->id]);

    $createParams = [
        'billable_metric_id' => $metric->id,
        'code' => 'filtered_charge',
        'charge_model' => 'standard',
        'properties' => ['amount' => '100'],
        'filters' => [[
            'invoice_display_name' => 'Filter 1',
            'properties' => ['amount' => '50'],
            'values' => [$metricFilter->key => [$metricFilter->values[0]]],
        ]],
    ];

    test()->postJson('/api/v1/plans/'.$plan->code.'/charges', ['charge' => $createParams], chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->where('charge.filters.0.invoice_display_name', 'Filter 1')
                ->where('charge.filters.0.properties.amount', '50')
                ->etc();
        });
});

it('creates a charge with taxes', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = App\Models\BillableMetric::factory()->for($organization)->create();
    $tax = App\Models\Tax::factory()->for($organization)->create();

    $createParams = [
        'billable_metric_id' => $metric->id,
        'code' => 'taxed_charge',
        'charge_model' => 'standard',
        'properties' => ['amount' => '100'],
        'tax_codes' => [$tax->code],
    ];

    test()->postJson('/api/v1/plans/'.$plan->code.'/charges', ['charge' => $createParams], chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($tax) {
            $json->count('charge.taxes', 1)
                ->where('charge.taxes.0.code', $tax->code)
                ->etc();
        });
});

it('accepts cascade_updates on charge create', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = App\Models\BillableMetric::factory()->for($organization)->create();

    $createParams = [
        'billable_metric_id' => $metric->id,
        'code' => 'cascaded_charge',
        'charge_model' => 'standard',
        'properties' => ['amount' => '100'],
        'cascade_updates' => true,
    ];

    test()->postJson('/api/v1/plans/'.$plan->code.'/charges', ['charge' => $createParams], chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJsonPath('charge.code', 'cascaded_charge');
});

it('requires an api permission to write plan charges', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['plan' => ['read']]), $apiKey->id],
    );

    test()->postJson('/api/v1/plans/'.$plan->code.'/charges', ['charge' => [
        'code' => 'x',
        'charge_model' => 'standard',
    ]], chargesBearer([$organization, $apiKey]))
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'write_action_not_allowed_for_plan',
        ]);
});

// -- PUT /api/v1/plans/:plan_code/charges/:code --------------------------------

it('updates the charge', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $metric = App\Models\BillableMetric::factory()->for($organization)->create();
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id, 'billable_metric_id' => $metric->id,
    ]);

    test()->putJson('/api/v1/plans/'.$plan->code.'/charges/'.$charge->code, ['charge' => [
        'invoice_display_name' => 'Updated Charge Name',
        'charge_model' => 'standard',
        'properties' => ['amount' => '200'],
    ]], chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->where('charge.invoice_display_name', 'Updated Charge Name')
                ->where('charge.properties.amount', '200')
                ->etc();
        });
});

it('returns not found error when plan does not exist on charge update', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $charge = Charge::factory()->standard()->create();

    test()->putJson('/api/v1/plans/invalid_code/charges/'.$charge->code, ['charge' => [
        'properties' => ['amount' => '200'],
    ]], chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when charge does not exist on update', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->putJson('/api/v1/plans/'.$plan->code.'/charges/invalid_code', ['charge' => [
        'properties' => ['amount' => '200'],
    ]], chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'charge_not_found']);
});

// -- DELETE /api/v1/plans/:plan_code/charges/:code ------------------------------

it('soft deletes the charge', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id,
    ]);

    test()->deleteJson('/api/v1/plans/'.$plan->code.'/charges/'.$charge->code, [], chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJsonPath('charge.lago_id', (string) $charge->id);

    expect($charge->fresh()->deleted_at)->not->toBeNull();
});

it('returns not found error when plan does not exist on charge destroy', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $charge = Charge::factory()->standard()->create();

    test()->deleteJson('/api/v1/plans/invalid_code/charges/'.$charge->code, [], chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found error when charge does not exist on destroy', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->deleteJson('/api/v1/plans/'.$plan->code.'/charges/invalid_code', [], chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'charge_not_found']);
});

// -- GET /api/v1/plans/:plan_code/charges/:charge_code/filters ------------------

it('returns the charge filters', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id,
    ]);
    $filter = App\Models\ChargeFilter::factory()->forCharge($charge)->create([
        'invoice_display_name' => 'Region filter',
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges/'.$charge->code.'/filters', chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($filter) {
            $json->count('filters', 1)
                ->where('filters.0.lago_id', (string) $filter->id)
                ->where('filters.0.invoice_display_name', 'Region filter')
                ->etc();
        });
});

it('returns filter pagination metadata', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id,
    ]);
    App\Models\ChargeFilter::factory()->count(2)->forCharge($charge)->create();

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges/'.$charge->code.'/filters?per_page=1', chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('filters', 1)->etc()
                ->where('meta.current_page', 1)
                ->where('meta.total_pages', 2)
                ->where('meta.total_count', 2);
        });
});

it('returns not found error when charge does not exist on filters index', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges/invalid_code/filters', chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'charge_not_found']);
});

// -- GET /api/v1/plans/:plan_code/charges/:charge_code/filters/:id ---------------

it('returns the charge filter', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id,
    ]);
    $filter = App\Models\ChargeFilter::factory()->forCharge($charge)->create();

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges/'.$charge->code.'/filters/'.$filter->id, chargesBearer([$organization, $apiKey]))
        ->assertOk()
        ->assertJsonPath('filter.lago_id', (string) $filter->id);
});

it('returns not found error when charge filter does not exist', function (): void {
    [$organization, $apiKey] = chargesOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $charge = Charge::factory()->standard()->create([
        'plan_id' => $plan->id, 'organization_id' => $organization->id,
    ]);

    test()->getJson('/api/v1/plans/'.$plan->code.'/charges/'.$charge->code.'/filters/'.Str::uuid(), chargesBearer([$organization, $apiKey]))
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'charge_filter_not_found']);
});
