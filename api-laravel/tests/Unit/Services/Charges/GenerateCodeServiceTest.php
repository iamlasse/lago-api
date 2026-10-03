<?php

declare(strict_types=1);

use App\Services\Charges\GenerateCodeService;

/**
 * Port of spec/services/charges/generate_code_service_spec.rb — unique
 * charge codes derived from the billable metric code.
 *
 * Wiring check against Rails: GenerateCodeService is called from
 * Plans::CreateService and Plans::UpdateService (charge_params_with_code),
 * NOT from Charges::CreateService — the port matches (Plans\CreateService
 * and Plans\UpdateService call it; Charges\CreateService validates the
 * given code for uniqueness instead).
 */
function generateCodeFixture(): array
{
    $organization = App\Models\Organization::factory()->create();
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id]);
    $billableMetric = App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'api_calls',
    ]);

    return compact('organization', 'plan', 'billableMetric');
}

function generateCodeCharge(array $f, string $code, ?string $metricCode = null): void
{
    $metric = $f['billableMetric'];
    if ($metricCode !== null) {
        $metric = App\Models\BillableMetric::factory()->create([
            'organization_id' => $f['organization']->id,
            'code' => $metricCode,
        ]);
    }

    App\Models\Charge::factory()->create([
        'plan_id' => $f['plan']->id,
        'organization_id' => $f['organization']->id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
        'code' => $code,
    ]);
}

it('generates the metric code without a suffix when no charges exist', function (): void {
    $f = generateCodeFixture();

    expect(GenerateCodeService::call(plan: $f['plan'], billableMetric: $f['billableMetric'])->code)
        ->toBe('api_calls');
})->group('ledger:svc:Charges.GenerateCodeService');

it('suffixes _2 when a charge already uses the base code', function (): void {
    $f = generateCodeFixture();
    generateCodeCharge($f, 'api_calls');

    expect(GenerateCodeService::call(plan: $f['plan'], billableMetric: $f['billableMetric'])->code)
        ->toBe('api_calls_2');
})->group('ledger:svc:Charges.GenerateCodeService');

it('skips to the next available suffix', function (): void {
    $f = generateCodeFixture();
    generateCodeCharge($f, 'api_calls');
    generateCodeCharge($f, 'api_calls_2');

    expect(GenerateCodeService::call(plan: $f['plan'], billableMetric: $f['billableMetric'])->code)
        ->toBe('api_calls_3');
})->group('ledger:svc:Charges.GenerateCodeService');

it('uses one greater than the maximum suffix, ignoring gaps', function (): void {
    $f = generateCodeFixture();
    generateCodeCharge($f, 'api_calls');
    generateCodeCharge($f, 'api_calls_3');
    generateCodeCharge($f, 'api_calls_5');

    expect(GenerateCodeService::call(plan: $f['plan'], billableMetric: $f['billableMetric'])->code)
        ->toBe('api_calls_6');
})->group('ledger:svc:Charges.GenerateCodeService');

it('ignores similar but non-matching prefixes', function (): void {
    $f = generateCodeFixture();
    generateCodeCharge($f, 'api_calls_premium', metricCode: 'api_calls_premium');

    expect(GenerateCodeService::call(plan: $f['plan'], billableMetric: $f['billableMetric'])->code)
        ->toBe('api_calls');
})->group('ledger:svc:Charges.GenerateCodeService');

it('ignores child (overridden) charges when scanning for suffixes', function (): void {
    $f = generateCodeFixture();
    generateCodeCharge($f, 'api_calls');

    // A child charge (parent_id set) must not influence the generated code.
    App\Models\Charge::factory()->create([
        'plan_id' => $f['plan']->id,
        'organization_id' => $f['organization']->id,
        'billable_metric_id' => $f['billableMetric']->id,
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
        'code' => 'api_calls_9',
        'parent_id' => App\Models\Charge::query()->where('code', 'api_calls')->first()->id,
    ]);

    expect(GenerateCodeService::call(plan: $f['plan'], billableMetric: $f['billableMetric'])->code)
        ->toBe('api_calls_2');
})->group('ledger:svc:Charges.GenerateCodeService');
