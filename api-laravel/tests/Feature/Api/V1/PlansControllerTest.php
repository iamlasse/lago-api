<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/plans',
    'ledger:rest:GET:/api/v1/plans',
    'ledger:rest:GET:/api/v1/plans/:code',
    'ledger:rest:PUT:/api/v1/plans/:code',
    'ledger:rest:PATCH:/api/v1/plans/:code',
    'ledger:rest:PATCH:/api/v2/plans/:code',
    'ledger:rest:DELETE:/api/v1/plans/:code',
);

use App\Models\Plan;
use Illuminate\Support\Str;
use App\Models\Organization;

/**
 * Port of Rails' spec/requests/api/v1/plans_controller_spec.rb.
 *
 * Scenarios not ported (dependencies do not exist yet):
 * - plan metadata persistence (Metadata::ItemMetadata);
 * - usage_thresholds / entitlements payloads (serializer TODOs);
 * - FixedChargeEvent emission on plan update (apply_units_immediately);
 * - applied_pricing_unit conversion-rate updates (premium pricing units).
 */
function planOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function getPlansWithToken(string $path, array $params = [], ?string $token = null): Illuminate\Testing\TestResponse
{
    $url = $params === [] ? $path : $path.'?'.http_build_query($params);

    return test()->getJson($url, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

function planCreateParams(Organization $organization, array $overrides = []): array
{
    return array_merge([
        'name' => 'P1',
        'invoice_display_name' => 'P1 invoice name',
        'code' => 'plan_code',
        'interval' => 'weekly',
        'description' => 'description',
        'amount_cents' => 100,
        'amount_currency' => 'EUR',
        'trial_period' => 1,
        'pay_in_advance' => false,
        'charges' => [],
        'fixed_charges' => [],
    ], $overrides);
}

// -- POST /api/v1/plans -------------------------------------------------------

it('creates a plan with charges and fixed charges', function (): void {
    [$organization, $apiKey] = planOrganization();
    $tax = App\Models\Tax::factory()->for($organization)->create();
    $billableMetric = App\Models\BillableMetric::factory()->for($organization)->create();
    $addOn = App\Models\AddOn::factory()->for($organization)->create();

    $createParams = planCreateParams($organization, [
        'minimum_commitment' => [
            'amount_cents' => 1000,
            'invoice_display_name' => 'Minimum commitment',
        ],
        'charges' => [[
            'billable_metric_id' => $billableMetric->id,
            'code' => 'charge_code',
            'charge_model' => 'standard',
            'pay_in_advance' => true,
            'invoiceable' => false,
            'regroup_paid_fees' => 'invoice',
            'properties' => ['amount' => '0.22'],
            'tax_codes' => [$tax->code],
        ]],
        'fixed_charges' => [[
            'code' => 'fixed_charge_code',
            'invoice_display_name' => 'Fixed charge 1',
            'units' => 1,
            'add_on_id' => $addOn->id,
            'charge_model' => 'standard',
            'pay_in_advance' => true,
            'prorated' => true,
            'properties' => ['amount' => '10'],
            'tax_codes' => [$tax->code],
        ]],
        'usage_thresholds' => [
            ['amount_cents' => 100, 'threshold_display_name' => 'Threshold 1'],
        ],
    ]);

    $this->postJson('/api/v1/plans', ['plan' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($createParams, $tax): void {
        $json->where('plan.lago_id', fn ($id) => is_string($id) && $id !== '')
            ->where('plan.code', $createParams['code'])
            ->where('plan.name', $createParams['name'])
            ->where('plan.invoice_display_name', $createParams['invoice_display_name'])
            ->where('plan.created_at', fn ($at) => is_string($at) && $at !== '')
            ->where('plan.charges.0.lago_id', fn ($id) => is_string($id) && $id !== '')
            ->where('plan.charges.0.code', 'charge_code')
            ->where('plan.fixed_charges.0.lago_id', fn ($id) => is_string($id) && $id !== '')
            ->where('plan.fixed_charges.0.code', 'fixed_charge_code')
            ->where('plan.fixed_charges.0.taxes.0.code', $tax->code)
            ->etc();
    });
});

it('returns an error when interval is empty', function (): void {
    [$organization, $apiKey] = planOrganization();

    $createParams = planCreateParams($organization, ['interval' => null]);

    $this->postJson('/api/v1/plans', ['plan' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertStatus(422)->assertExactJson([
        'status' => 422,
        'error' => 'Unprocessable Entity',
        'code' => 'validation_errors',
        'error_details' => ['interval' => ['value_is_invalid']],
    ]);
});

it('ignores premium fields when license is not premium', function (): void {
    [$organization, $apiKey] = planOrganization();
    $billableMetric = App\Models\BillableMetric::factory()->for($organization)->create();

    $createParams = planCreateParams($organization, [
        'charges' => [[
            'billable_metric_id' => $billableMetric->id,
            'code' => 'charge_code',
            'charge_model' => 'standard',
            'pay_in_advance' => true,
            'invoiceable' => false,
            'regroup_paid_fees' => 'invoice',
            'properties' => ['amount' => '0.22'],
        ]],
    ]);

    $this->postJson('/api/v1/plans', ['plan' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->where('plan.charges.0.invoiceable', true)
            ->where('plan.charges.0.regroup_paid_fees', null)
            ->etc();
    });
});

it('creates a plan with graduated charges', function (): void {
    [$organization, $apiKey] = planOrganization();
    $billableMetric = App\Models\BillableMetric::factory()->for($organization)->create();

    $createParams = planCreateParams($organization, [
        'charges' => [[
            'billable_metric_id' => $billableMetric->id,
            'code' => 'graduated_charge',
            'charge_model' => 'graduated',
            'properties' => [
                'graduated_ranges' => [
                    ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '0', 'flat_amount' => '10'],
                    ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '0.5', 'flat_amount' => '20'],
                ],
            ],
        ]],
    ]);

    $this->postJson('/api/v1/plans', ['plan' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->where('plan.charges.0.charge_model', 'graduated')
            ->where('plan.charges.0.properties.graduated_ranges.0.flat_amount', '10')
            ->etc();
    });
});

it('creates a plan without charges', function (): void {
    [$organization, $apiKey] = planOrganization();

    $createParams = planCreateParams($organization);

    $this->postJson('/api/v1/plans', ['plan' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->where('plan.code', 'plan_code')
            ->where('plan.charges', [])
            ->etc();
    });
});

it('returns a 404 response with unknown tax code on charge', function (): void {
    [$organization, $apiKey] = planOrganization();
    $billableMetric = App\Models\BillableMetric::factory()->for($organization)->create();

    $createParams = planCreateParams($organization, [
        'charges' => [[
            'billable_metric_id' => $billableMetric->id,
            'code' => 'charge_code',
            'charge_model' => 'standard',
            'properties' => ['amount' => '0.22'],
            'tax_codes' => ['unknown'],
        ]],
    ]);

    $this->postJson('/api/v1/plans', ['plan' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertExactJson([
        'status' => 404,
        'error' => 'Not Found',
        'code' => 'tax_not_found',
    ]);
});

it('returns a 404 response when billable metric for charge is not found', function (): void {
    [$organization, $apiKey] = planOrganization();

    $createParams = planCreateParams($organization, [
        'charges' => [[
            'billable_metric_id' => 'unknown',
            'code' => 'charge_code',
            'charge_model' => 'standard',
            'properties' => ['amount' => '0.22'],
        ]],
    ]);

    $this->postJson('/api/v1/plans', ['plan' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertExactJson([
        'status' => 404,
        'error' => 'Not Found',
        'code' => 'billable_metrics_not_found',
    ]);
});

it('returns a 404 response when add on for fixed charge is not found', function (): void {
    [$organization, $apiKey] = planOrganization();

    $createParams = planCreateParams($organization, [
        'fixed_charges' => [[
            'add_on_id' => 'unknown',
            'code' => 'fixed_charge_code',
            'charge_model' => 'standard',
            'units' => 1,
            'properties' => ['amount' => '10'],
        ]],
    ]);

    $this->postJson('/api/v1/plans', ['plan' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertExactJson([
        'status' => 404,
        'error' => 'Not Found',
        'code' => 'add_ons_not_found',
    ]);
});

it('requires an api permission to write plans', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = planOrganization();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['plan' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/plans', ['plan' => planCreateParams($organization)], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()->assertExactJson([
        'status' => 403,
        'error' => 'Forbidden',
        'code' => 'write_action_not_allowed_for_plan',
    ]);
});

// -- PUT /api/v1/plans/:code --------------------------------------------------

it('updates a plan', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'plan_code']);

    $this->putJson('/api/v1/plans/plan_code', ['plan' => [
        'name' => 'P1 updated',
        'code' => 'plan_code',
        'interval' => 'monthly',
        'amount_cents' => 200,
        'amount_currency' => 'EUR',
        'pay_in_advance' => false,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($plan): void {
            $json->where('plan.lago_id', (string) $plan->id)
                ->where('plan.name', 'P1 updated')
                ->where('plan.amount_cents', 200)
                ->etc();
        });
});

it('returns not_found error when updated plan does not exist', function (): void {
    [, $apiKey] = planOrganization();

    $this->putJson('/api/v1/plans/'.Str::uuid(), ['plan' => [
        'name' => 'P1',
        'interval' => 'monthly',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns unprocessable_entity error when plan code already exists in organization scope', function (): void {
    [$organization, $apiKey] = planOrganization();
    Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'taken_code']);
    Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'plan_code']);

    $this->putJson('/api/v1/plans/plan_code', ['plan' => [
        'name' => 'P1',
        'code' => 'taken_code',
        'interval' => 'monthly',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(422)
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('status', 422)
                ->where('code', 'validation_errors')
                ->has('error_details.code')
                ->etc();
        });
});

// -- PATCH /api/v1/plans/:code -------------------------------------------------
// Rails routes PATCH and PUT to the same PlansController#update (resources
// :plans draws both verbs; no PATCH-specific branch exists), so the scenarios
// below port the PUT section's expectations to the PATCH verb.

it('updates a plan via PATCH', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'plan_code']);

    $this->patchJson('/api/v1/plans/plan_code', ['plan' => [
        'name' => 'P1 updated',
        'code' => 'plan_code',
        'interval' => 'monthly',
        'amount_cents' => 200,
        'amount_currency' => 'EUR',
        'pay_in_advance' => false,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($plan): void {
            $json->where('plan.lago_id', (string) $plan->id)
                ->where('plan.name', 'P1 updated')
                ->where('plan.amount_cents', 200)
                ->etc();
        });
});

it('returns not_found error when the plan updated via PATCH does not exist', function (): void {
    [, $apiKey] = planOrganization();

    $this->patchJson('/api/v1/plans/'.Str::uuid(), ['plan' => [
        'name' => 'P1',
        'interval' => 'monthly',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('mirrors the plan update via PATCH at v2 with the beta header', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'plan_code']);

    $this->patchJson('/api/v2/plans/plan_code', ['plan' => [
        'name' => 'P1 updated',
        'code' => 'plan_code',
        'interval' => 'monthly',
        'amount_cents' => 200,
        'amount_currency' => 'EUR',
        'pay_in_advance' => false,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('plan.lago_id', (string) $plan->id)
        ->assertJsonPath('plan.name', 'P1 updated');
});

// -- GET /api/v1/plans/:code --------------------------------------------------

it('returns the plan', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    getPlansWithToken('/api/v1/plans/'.$plan->code, [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($plan): void {
            $json->where('plan.lago_id', (string) $plan->id)
                ->where('plan.code', $plan->code)
                ->etc();
        });
});

it('returns the plan whose code contains a dot', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'plan.code.v1']);

    getPlansWithToken('/api/v1/plans/'.$plan->code, [], $apiKey->value)
        ->assertOk()
        ->assertJsonPath('plan.lago_id', (string) $plan->id)
        ->assertJsonPath('plan.code', 'plan.code.v1');
});

it('returns not found when the plan is discarded', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    Illuminate\Support\Facades\DB::update('update plans set deleted_at = now() where id = ?', [$plan->id]);

    getPlansWithToken('/api/v1/plans/'.$plan->code, [], $apiKey->value)
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('returns not found when plan does not exist', function (): void {
    [, $apiKey] = planOrganization();

    getPlansWithToken('/api/v1/plans/'.Str::uuid(), [], $apiKey->value)
        ->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('requires an api permission to read a plan', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['plan' => ['write']]), $apiKey->id],
    );

    getPlansWithToken('/api/v1/plans/'.$plan->code, [], $apiKey->value)
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'read_action_not_allowed_for_plan',
        ]);
});

// -- DELETE /api/v1/plans/:code -----------------------------------------------

it('marks the plan as pending deletion and returns it', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/plans/'.$plan->code, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($plan): void {
        $json->where('plan.lago_id', (string) $plan->id)
            ->where('plan.code', $plan->code)
            ->where('plan.applicable_usage_thresholds', [])
            ->where('plan.entitlements', [])
            ->etc();
    });

    expect($plan->fresh()->pending_deletion)->toBeTrue();
});

it('marks children plans as pending deletion', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $childrenPlan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'parent_id' => $plan->id,
    ]);

    $this->deleteJson('/api/v1/plans/'.$plan->code, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();

    expect($childrenPlan->fresh()->pending_deletion)->toBeTrue();
});

it('returns not_found error when deleted plan does not exist', function (): void {
    [, $apiKey] = planOrganization();

    $this->deleteJson('/api/v1/plans/'.Str::uuid(), [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()
        ->assertExactJson(['status' => 404, 'error' => 'Not Found', 'code' => 'plan_not_found']);
});

it('requires an api permission to delete a plan', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['plan' => ['read']]), $apiKey->id],
    );

    $this->deleteJson('/api/v1/plans/'.$plan->code, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'write_action_not_allowed_for_plan',
        ]);
});

// -- GET /api/v1/plans --------------------------------------------------------

it('returns plans', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    getPlansWithToken('/api/v1/plans?page=1&per_page=1', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($plan): void {
            $json->count('plans', 1)
                ->where('plans.0.lago_id', (string) $plan->id)
                ->where('plans.0.code', $plan->code)
                ->where('plans.0.entitlements', [])
                ->etc();
        });
});

it('includes the pending for deletion plan in the response', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    Plan::factory()->create(['organization_id' => $organization->id, 'pending_deletion' => true]);

    getPlansWithToken('/api/v1/plans', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('plans', 2)->etc();
        });
});

it('returns plans with correct meta data', function (): void {
    [$organization, $apiKey] = planOrganization();
    Plan::factory()->count(2)->create(['organization_id' => $organization->id]);

    getPlansWithToken('/api/v1/plans?page=1&per_page=1', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('plans', 1)->etc()
                ->where('meta.current_page', 1)
                ->where('meta.next_page', 2)
                ->where('meta.prev_page', null)
                ->where('meta.total_pages', 2)
                ->where('meta.total_count', 2);
        });
});

it('requires an api permission to read plans on the index', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = planOrganization();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['plan' => ['write']]), $apiKey->id],
    );

    getPlansWithToken('/api/v1/plans', [], $apiKey->value)
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'read_action_not_allowed_for_plan',
        ]);
});

// -- v2 mirror ------------------------------------------------------------------

it('mirrors the plans endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = planOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'v2_plan']);

    $this->postJson('/api/v2/plans', ['plan' => planCreateParams($organization, ['code' => 'v2_created'])], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('plan.code', 'v2_created');

    getPlansWithToken('/api/v2/plans', [], $apiKey->value)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');

    getPlansWithToken('/api/v2/plans/'.$plan->code, [], $apiKey->value)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('plan.lago_id', (string) $plan->id);

    $this->putJson('/api/v2/plans/'.$plan->code, ['plan' => [
        'name' => 'V2 updated',
        'interval' => 'monthly',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('plan.name', 'V2 updated');

    $this->deleteJson('/api/v2/plans/'.$plan->code, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});
