<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/billable_metrics',
    'ledger:rest:POST:/api/v1/billable_metrics/evaluate_expression',
    'ledger:rest:GET:/api/v1/billable_metrics',
    'ledger:rest:GET:/api/v1/billable_metrics/:code',
    'ledger:rest:PUT:/api/v1/billable_metrics/:code',
    'ledger:rest:DELETE:/api/v1/billable_metrics/:code',
);

use App\Models\Plan;
use App\Models\Charge;
use App\Models\Organization;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' spec/requests/api/v1/billable_metrics_controller_spec.rb.
 *
 * Scenarios not ported (dependencies do not exist yet):
 * - the "with filters" create scenario (BillableMetricFilters slice — the
 *   create service drops the permitted filters param for now, so the
 *   response carries filters: []).
 *
 * TODO(port): the evaluate_expression scenarios exercise the documented
 * divergence until Lago::ExpressionParser lands — a non-blank expression
 * currently gets Rails' invalid_expression envelope instead of being
 * evaluated (the Rails expectation "2.0" is noted inline).
 */
function metricOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

/**
 * Rails' :premium spec tag — License.premium? is true while a license key
 * is configured.
 */
/**
 * Rails' :premium spec tag — License.premium? is true while a license key
 * is configured.
 */
function withMetricPremiumLicense(callable $scenario): void
{
    putenv('LAGO_LICENSE=premium-license-token');
    $_ENV['LAGO_LICENSE'] = 'premium-license-token';

    try {
        $scenario();
    } finally {
        putenv('LAGO_LICENSE');
        unset($_ENV['LAGO_LICENSE']);
    }
}

// -- POST /api/v1/billable_metrics ---------------------------------------------

it('creates a billable metric', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $createParams = [
        'name' => 'BM1',
        'code' => 'BM1_code',
        'description' => 'description',
        'aggregation_type' => 'sum_agg',
        'field_name' => 'amount_sum',
        'expression' => '1 + 2',
        'recurring' => true,
        'rounding_function' => 'round',
        'rounding_precision' => 2,
    ];

    $this->postJson('/api/v1/billable_metrics', ['billable_metric' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($createParams): void {
        $json->where('billable_metric.code', $createParams['code'])
            ->where('billable_metric.name', $createParams['name'])
            ->where('billable_metric.recurring', true)
            ->where('billable_metric.expression', '1 + 2')
            ->where('billable_metric.rounding_function', 'round')
            ->where('billable_metric.rounding_precision', 2)
            ->where('billable_metric.aggregation_type', 'sum_agg')
            ->where('billable_metric.field_name', 'amount_sum')
            ->where('billable_metric.filters', [])
            ->where('billable_metric.active_subscriptions_count', 0)
            ->where('billable_metric.plans_count', 0)
            ->has('billable_metric.lago_id')
            ->has('billable_metric.created_at')
            ->etc();
    });
});

it('creates a billable metric with weighted sum aggregation', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $this->postJson('/api/v1/billable_metrics', ['billable_metric' => [
        'name' => 'BM1',
        'code' => 'BM1_code',
        'description' => 'description',
        'aggregation_type' => 'weighted_sum_agg',
        'field_name' => 'amount_sum',
        'recurring' => true,
        'weighted_interval' => 'seconds',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('billable_metric.recurring', true)
                ->where('billable_metric.aggregation_type', 'weighted_sum_agg')
                ->where('billable_metric.weighted_interval', 'seconds')
                ->has('billable_metric.lago_id')
                ->etc();
        });
});

it('rejects a scalar billable_metric param with bad_request', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $this->postJson('/api/v1/billable_metrics', ['billable_metric' => 'BL'], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertBadRequest()->assertExactJson([
        'status' => 400,
        'error' => 'BadRequest: param is missing or the value is empty or invalid: billable_metric',
    ]);
});

// -- PUT /api/v1/billable_metrics/:code ------------------------------------------

it('updates a billable metric', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id, 'aggregation_type' => 0, 'field_name' => null]);

    $this->putJson('/api/v1/billable_metrics/'.$billableMetric->code, ['billable_metric' => [
        'name' => 'BM1',
        'code' => 'BM1_code',
        'description' => 'description',
        'aggregation_type' => 'sum_agg',
        'field_name' => 'amount_sum',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($billableMetric): void {
            $json->where('billable_metric.lago_id', $billableMetric->id)
                ->where('billable_metric.code', 'BM1_code')
                ->where('billable_metric.filters', [])
                ->etc();
        });
});

it('returns not_found when the updated billable metric does not exist', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $this->putJson('/api/v1/billable_metrics/'.Illuminate\Support\Str::uuid(), ['billable_metric' => [
        'name' => 'BM1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertExactJson([
            'status' => 404,
            'error' => 'Not Found',
            'code' => 'billable_metric_not_found',
        ]);
});

it('rejects a billable metric code that already exists in the organization', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $anotherMetric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/billable_metrics/'.$billableMetric->code, ['billable_metric' => [
        'name' => 'BM1',
        'code' => $anotherMetric->code,
        'aggregation_type' => 'sum_agg',
        'field_name' => 'amount_sum',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['code' => ['value_already_exist']],
        ]);
});

it('updates a billable metric with weighted sum aggregation', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id, 'aggregation_type' => 0, 'field_name' => null]);

    $this->putJson('/api/v1/billable_metrics/'.$billableMetric->code, ['billable_metric' => [
        'name' => 'BM1',
        'code' => 'BM1_code',
        'description' => 'description',
        'aggregation_type' => 'weighted_sum_agg',
        'field_name' => 'amount_sum',
        'recurring' => true,
        'weighted_interval' => 'seconds',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('billable_metric.recurring', true)
                ->where('billable_metric.aggregation_type', 'weighted_sum_agg')
                ->where('billable_metric.weighted_interval', 'seconds')
                ->etc();
        });
});

it('only updates name and description when the billable metric is attached to a plan', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'aggregation_type' => 0,
        'field_name' => null,
        'code' => 'locked_code',
    ]);

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    Charge::factory()->create([
        'plan_id' => $plan->id,
        'billable_metric_id' => $billableMetric->id,
        'organization_id' => $organization->id,
    ]);

    $this->putJson('/api/v1/billable_metrics/locked_code', ['billable_metric' => [
        'name' => 'Renamed',
        'description' => 'New description',
        'code' => 'hacked_code',
        'aggregation_type' => 'sum_agg',
        'field_name' => 'hacked_field',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('billable_metric.name', 'Renamed')
                ->where('billable_metric.description', 'New description')
                // Only name and description are editable when attached.
                ->where('billable_metric.code', 'locked_code')
                ->where('billable_metric.aggregation_type', 'count_agg')
                ->where('billable_metric.field_name', null)
                ->etc();
        });

    expect($billableMetric->refresh()->code)->toBe('locked_code');
});

// -- GET /api/v1/billable_metrics/:code -------------------------------------------

it('returns a billable metric', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/billable_metrics/'.$billableMetric->code, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($billableMetric): void {
        $json->where('billable_metric.lago_id', $billableMetric->id)
            ->where('billable_metric.code', $billableMetric->code)
            ->etc();
    });
});

it('returns not_found when the billable metric does not exist', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $this->getJson('/api/v1/billable_metrics/'.Illuminate\Support\Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

it('returns not_found when the billable metric is deleted', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id, 'deleted_at' => now()]);

    $this->getJson('/api/v1/billable_metrics/'.$billableMetric->code, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- DELETE /api/v1/billable_metrics/:code -----------------------------------------

it('deletes a billable metric', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/billable_metrics/'.$billableMetric->code, headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk();

    expect(BillableMetric::count())->toBe(0)
        ->and(BillableMetric::withTrashed()->count())->toBe(1);
});

it('returns the deleted billable metric', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/billable_metrics/'.$billableMetric->code, headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($billableMetric): void {
        $json->where('billable_metric.lago_id', $billableMetric->id)
            ->where('billable_metric.code', $billableMetric->code)
            ->etc();
    });
});

it('returns not_found when deleting a billable metric that does not exist', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $this->deleteJson('/api/v1/billable_metrics/'.Illuminate\Support\Str::uuid(), headers: [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- GET /api/v1/billable_metrics ---------------------------------------------------

it('returns the organization billable metrics', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/billable_metrics', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($billableMetric): void {
            $json->where('billable_metrics.0.lago_id', $billableMetric->id)
                ->where('billable_metrics.0.code', $billableMetric->code)
                ->count('billable_metrics', 1)
                ->etc();
        });
});

it('returns billable metrics with pagination meta', function (): void {
    [$organization, $apiKey] = metricOrganization();

    BillableMetric::factory()->count(2)->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/billable_metrics?page=1&per_page=1', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->count('billable_metrics', 1)
            ->where('meta.current_page', 1)
            ->where('meta.next_page', 2)
            ->where('meta.prev_page', null)
            ->where('meta.total_pages', 2)
            ->where('meta.total_count', 2)
            ->etc();
    });
});

// -- POST /api/v1/billable_metrics/evaluate_expression ------------------------------

it('requires the expression on evaluate_expression', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $this->postJson('/api/v1/billable_metrics/evaluate_expression', [
        'expression' => '',
        'event' => [],
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['expression' => ['value_is_mandatory']],
        ]);
});

// TODO(port): the Rails expectation is
//   ['expression_result' => ['value' => '2.0']]
// for expression "round(event.properties.value)" with properties {value: "2.4"}
// — unreachable until Lago::ExpressionParser is ported; the documented
// divergence answers invalid_expression instead.
it('rejects a non-blank expression while the expression parser is not ported', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $this->postJson('/api/v1/billable_metrics/evaluate_expression', [
        'expression' => 'round(event.properties.value)',
        'event' => [
            'code' => 'bm_code',
            'timestamp' => now()->getTimestamp(),
            'properties' => ['value' => '2.4'],
        ],
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertExactJson([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'validation_errors',
            'error_details' => ['expression' => ['invalid_expression']],
        ]);
});

// -- v2 mirror ------------------------------------------------------------------------

it('mirrors the billable metric endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = metricOrganization();

    $this->postJson('/api/v2/billable_metrics', ['billable_metric' => [
        'name' => 'BM1',
        'code' => 'BM1_code',
        'aggregation_type' => 'sum_agg',
        'field_name' => 'amount_sum',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('billable_metric.code', 'BM1_code');

    $this->getJson('/api/v2/billable_metrics', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 1);

    $this->getJson('/api/v2/billable_metrics/not_a_metric', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()->assertHeader('X-Lago-Endpoint-Status', 'beta');
});

// -- api permissions --------------------------------------------------------------------

it('requires an api permission to write billable metrics', function (): void {
    withMetricPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = metricOrganization();

        DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['billable_metric' => ['read']]), $apiKey->id],
        );

        $this->postJson('/api/v1/billable_metrics', ['billable_metric' => [
            'name' => 'BM1',
            'code' => 'BM1_code',
            'aggregation_type' => 'sum_agg',
            'field_name' => 'amount_sum',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertForbidden()
            ->assertExactJson([
                'status' => 403,
                'error' => 'Forbidden',
                'code' => 'write_action_not_allowed_for_billable_metric',
            ]);
    });
});

it('allows the write when the api permission grants it', function (): void {
    withMetricPremiumLicense(function (): void {
        config(['lago.license' => 'premium-license-token']);

        [$organization, $apiKey] = metricOrganization();

        DB::update(
            'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
            ['api_permissions', $organization->id],
        );
        DB::update(
            'update api_keys set permissions = ?::jsonb where id = ?',
            [json_encode(['billable_metric' => ['write']]), $apiKey->id],
        );

        $this->postJson('/api/v1/billable_metrics', ['billable_metric' => [
            'name' => 'BM1',
            'code' => 'BM1_code',
            'aggregation_type' => 'sum_agg',
            'field_name' => 'amount_sum',
        ]], ['Authorization' => 'Bearer '.$apiKey->value])
            ->assertOk()
            ->assertJsonPath('billable_metric.code', 'BM1_code');
    });
});
