<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Charge;
use App\Enums\ChargeModel;
use App\Models\BillableMetric;
use App\Services\Charges\CreateService;
use App\Services\Failures\NotFoundFailure;
use Database\Factories\BillableMetricFactory;

/**
 * Port of spec/services/charges/create_service_spec.rb (core scenarios) plus
 * the charge-model validations exercised through the service
 * (spec/models/charge_spec.rb validation matrix highlights).
 */
function chargePlan(): Plan
{
    return Plan::factory()->create();
}

function chargeMetric($plan, int $aggregationType = BillableMetricFactory::SUM_AGG, bool $recurring = false): BillableMetric
{
    return BillableMetric::factory()->create([
        'organization_id' => $plan->organization_id,
        'aggregation_type' => $aggregationType,
        'recurring' => $recurring,
    ]);
}

it('creates a standard charge', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'api-calls',
        'properties' => ['amount' => '12'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->charge->charge_model)->toBe(ChargeModel::Standard->value)
        ->and($result->charge->properties)->toBe(['amount' => '12'])
        ->and($result->charge->pay_in_advance)->toBeFalse()
        ->and($result->charge->prorated)->toBeFalse()
        ->and($result->charge->invoiceable)->toBeTrue();
})->group('ledger:svc:Charges.CreateService');

it('builds default properties when none are provided', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'package',
        'code' => 'pkg',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->charge->properties)->toBe([
            'package_size' => 1,
            'amount' => '0',
            'free_units' => 0,
        ]);
});

it('fails with an unknown billable metric', function (): void {
    $plan = chargePlan();

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => '00000000-0000-0000-0000-000000000000',
        'charge_model' => 'standard',
        'code' => 'x',
        'properties' => ['amount' => '10'],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('billable_metric');
});

it('fails when the charge code already exists on the plan', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metric->id,
        'code' => 'dupe',
        'charge_model' => 'standard',
        'properties' => ['amount' => '1'],
    ]);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'dupe',
        'properties' => ['amount' => '2'],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['code'])->toBe(['value_already_exist']);
});

// -- charge-model validation matrix (through the service) --------------------

it('rejects graduated_percentage without a premium license', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan, BillableMetricFactory::LATEST_AGG);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'graduated_percentage',
        'code' => 'gp',
        'properties' => [
            'graduated_percentage_ranges' => [
                ['from_value' => 0, 'to_value' => null, 'rate' => '0.1', 'flat_amount' => '0'],
            ],
        ],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['charge_model'])
        ->toBe(['graduated_percentage_requires_premium_license']);
});

it('rejects dynamic with a non-sum aggregation metric', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan, BillableMetricFactory::LATEST_AGG);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'dynamic',
        'code' => 'dyn',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['charge_model'])
        ->toBe(['invalid_aggregation_type_or_charge_model']);
});

it('rejects custom with a non-custom aggregation metric', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'custom',
        'code' => 'cst',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['charge_model'])
        ->toBe(['invalid_aggregation_type_or_charge_model']);
});

it('rejects pay_in_advance on volume charges', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'volume',
        'code' => 'vol',
        'pay_in_advance' => true,
        'properties' => [
            'volume_ranges' => [
                ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '0'],
            ],
        ],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['pay_in_advance'])
        ->toBe(['invalid_aggregation_type_or_charge_model']);
});

it('rejects pay_in_advance on a non-payable-in-advance aggregation', function (): void {
    $plan = chargePlan();
    // latest_agg is not in AGGREGATION_TYPES_PAYABLE_IN_ADVANCE.
    $metric = chargeMetric($plan, BillableMetricFactory::LATEST_AGG);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'pay_in_advance' => true,
        'properties' => ['amount' => '10'],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['pay_in_advance'])
        ->toBe(['invalid_aggregation_type_or_charge_model']);
});

it('ignores min_amount_cents without a premium license', function (): void {
    // Rails gates min_amount_cents behind License.premium? — the schema
    // default (0) stands without a license.
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'pay_in_advance' => true,
        'min_amount_cents' => 100,
        'properties' => ['amount' => '10'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->charge->min_amount_cents)->toBe(0);
});

it('ignores invoiceable false without a premium license', function (): void {
    // Rails gates invoiceable/regroup/min_amount_cents behind License.premium?
    // — without a license the input is ignored and the schema default stands.
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'invoiceable' => false,
        'properties' => ['amount' => '10'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->charge->invoiceable)->toBeTrue();
});

it('ignores regroup_paid_fees without a premium license', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'regroup_paid_fees' => 0,
        'properties' => ['amount' => '10'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->charge->regroup_paid_fees)->toBeNull();
});

it('accepts prorated on a recurring, pay-in-advance standard charge', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan, BillableMetricFactory::SUM_AGG, recurring: true);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'pay_in_advance' => true,
        'prorated' => true,
        'properties' => ['amount' => '10'],
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->charge->prorated)->toBeTrue();
});

it('rejects prorated on a metered charge', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan, BillableMetricFactory::SUM_AGG, recurring: false);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'prorated' => true,
        'properties' => ['amount' => '10'],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['prorated'])
        ->toBe(['invalid_billable_metric_or_charge_model']);
});

// -- nested filters -----------------------------------------------------------

it('creates nested charge filters', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    App\Models\BillableMetricFilter::query()->create([
        'billable_metric_id' => $metric->id,
        'organization_id' => $plan->organization_id,
        'key' => 'region',
        'values' => ['us', 'eu'],
    ]);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'properties' => ['amount' => '10'],
        'filters' => [
            ['values' => ['region' => ['us']], 'properties' => ['amount' => '42']],
        ],
    ]);

    expect($result->success())->toBeTrue();

    $filters = $result->charge->filters()->get();

    expect($filters)->toHaveCount(1)
        ->and($filters[0]->properties)->toBe(['amount' => '42'])
        ->and($filters[0]->code)->not->toBeNull()
        ->and($filters[0]->values()->count())->toBe(1);
});

it('fails a nested charge filter with empty values', function (): void {
    $plan = chargePlan();
    $metric = chargeMetric($plan);

    $result = CreateService::call(plan: $plan, params: [
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'properties' => ['amount' => '10'],
        'filters' => [
            ['values' => []],
        ],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['values'])->toBe(['value_is_mandatory']);
});
