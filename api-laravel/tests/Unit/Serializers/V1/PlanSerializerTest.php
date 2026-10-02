<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\AddOn;
use App\Models\Charge;
use App\Models\FixedCharge;
use App\Models\ChargeFilter;
use App\Models\BillableMetric;
use App\Models\ChargeFilterValue;
use App\Models\BillableMetricFilter;
use App\Serializers\V1\PlanSerializer;
use App\Serializers\V1\ChargeSerializer;
use App\Serializers\V1\FixedChargeSerializer;
use App\Serializers\V1\ChargeFilterSerializer;

/**
 * Ports of spec/serializers/v1/{plan,charge,fixed_charge,
 * charge_filter}_serializer_spec.rb.
 */
function serializerPlan(): Plan
{
    return Plan::factory()->create([
        'name' => 'Go Classic',
        'code' => 'go-classic',
        'interval' => 'monthly',
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
        'trial_period' => 0,
        'pay_in_advance' => false,
        'pending_deletion' => false,
    ]);
}

it('serializes a plan with literal snake_case keys', function (): void {
    $plan = serializerPlan();

    $payload = (new PlanSerializer($plan))->serialize();

    expect($payload['lago_id'])->toBe($plan->id)
        ->and($payload['name'])->toBe('Go Classic')
        ->and($payload['code'])->toBe('go-classic')
        ->and($payload['interval'])->toBe('monthly')
        ->and($payload['amount_cents'])->toBe(1000)
        ->and($payload['amount_currency'])->toBe('EUR')
        ->and($payload['pay_in_advance'])->toBeFalse()
        ->and($payload['bill_charges_monthly'])->toBeNull()
        ->and($payload['bill_fixed_charges_monthly'])->toBeFalse()
        ->and($payload['customers_count'])->toBe(0)
        ->and($payload['active_subscriptions_count'])->toBe(0)
        ->and($payload['draft_invoices_count'])->toBe(0)
        ->and($payload['pending_deletion'])->toBeFalse()
        ->and($payload['parent_id'])->toBeNull()
        ->and($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(array_key_exists('charges', $payload))->toBeFalse();
})->group('ledger:ser:V1.PlanSerializer');

it('serializes nested charges with the filters collection', function (): void {
    $plan = serializerPlan();
    $metric = BillableMetric::factory()->create(['organization_id' => $plan->organization_id, 'code' => 'api']);
    $metricFilter = BillableMetricFilter::query()->create([
        'billable_metric_id' => $metric->id,
        'organization_id' => $plan->organization_id,
        'key' => 'region',
        'values' => ['us', 'eu'],
    ]);

    $charge = Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metric->id,
        'code' => 'api',
        'charge_model' => 'standard',
        'properties' => ['amount' => '10', 'grouped_by' => ['region']],
    ]);

    $filter = ChargeFilter::query()->create([
        'charge_id' => $charge->id,
        'organization_id' => $plan->organization_id,
        'properties' => ['amount' => '20'],
        'code' => 'region-us_abc12345',
    ]);
    ChargeFilterValue::query()->create([
        'charge_filter_id' => $filter->id,
        'billable_metric_filter_id' => $metricFilter->id,
        'organization_id' => $plan->organization_id,
        'values' => ['us'],
    ]);

    $payload = (new PlanSerializer($plan, ['includes' => ['charges']]))->serialize();

    expect($payload['charges'])->toHaveCount(1);

    $serializedCharge = $payload['charges'][0];

    expect($serializedCharge['lago_id'])->toBe($charge->id)
        ->and($serializedCharge['lago_billable_metric_id'])->toBe($metric->id)
        ->and($serializedCharge['billable_metric_code'])->toBe('api')
        ->and($serializedCharge['charge_model'])->toBe('standard')
        ->and($serializedCharge['invoiceable'])->toBeTrue()
        ->and($serializedCharge['regroup_paid_fees'])->toBeNull()
        ->and($serializedCharge['min_amount_cents'])->toBe(0)
        ->and($serializedCharge['lago_parent_id'])->toBeNull()
        ->and($serializedCharge['applied_pricing_unit'])->toBeNull()
        // grouped_by deprecation: both keys are emitted.
        ->and($serializedCharge['properties']['grouped_by'])->toBe(['region'])
        ->and($serializedCharge['properties']['pricing_group_keys'])->toBe(['region'])
        ->and($serializedCharge['filters'])->toHaveCount(1)
        ->and($serializedCharge['filters'][0]['values'])->toBe(['region' => ['us']])
        ->and($serializedCharge['filters'][0]['properties'])->toBe(['amount' => '20']);
});

it('serializes a fixed charge with units as a BigDecimal-style string', function (): void {
    $plan = serializerPlan();
    $addOn = AddOn::factory()->create(['organization_id' => $plan->organization_id, 'code' => 'support']);

    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'add_on_id' => $addOn->id,
        'code' => 'support',
        'charge_model' => 'standard',
        'units' => 10,
        'properties' => ['amount' => '100'],
    ]);

    $payload = (new FixedChargeSerializer($fixedCharge, ['root_name' => 'fixed_charge']))->serialize();

    expect($payload['lago_id'])->toBe($fixedCharge->id)
        ->and($payload['lago_add_on_id'])->toBe($addOn->id)
        ->and($payload['add_on_code'])->toBe('support')
        ->and($payload['charge_model'])->toBe('standard')
        ->and($payload['units'])->toBe('10.0')
        ->and($payload['properties'])->toBe(['amount' => '100'])
        ->and(array_key_exists('taxes', $payload))->toBeFalse();

    // Subscription-scoped callers pre-resolve override units.
    $overridden = (new FixedChargeSerializer($fixedCharge, [
        'root_name' => 'fixed_charge',
        'effective_units_by_id' => [$fixedCharge->id => '25'],
    ]))->serialize();

    expect($overridden['units'])->toBe('25.0');

    expect(FixedChargeSerializer::serializeUnits('10'))->toBe('10.0')
        ->and(FixedChargeSerializer::serializeUnits('25.5000000000'))->toBe('25.5');
})->group('ledger:ser:V1.FixedChargeSerializer');

it('serializes a charge filter with the values hash', function (): void {
    $plan = serializerPlan();
    $metric = BillableMetric::factory()->create(['organization_id' => $plan->organization_id, 'code' => 'api']);
    $metricFilter = BillableMetricFilter::query()->create([
        'billable_metric_id' => $metric->id,
        'organization_id' => $plan->organization_id,
        'key' => 'region',
        'values' => ['us'],
    ]);
    $charge = Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metric->id,
        'code' => 'api',
        'charge_model' => 'standard',
        'properties' => ['amount' => '10'],
    ]);
    $filter = ChargeFilter::query()->create([
        'charge_id' => $charge->id,
        'organization_id' => $plan->organization_id,
        'invoice_display_name' => 'US only',
        'properties' => ['amount' => '20'],
        'code' => 'region-us_deadbeef',
    ]);
    ChargeFilterValue::query()->create([
        'charge_filter_id' => $filter->id,
        'billable_metric_filter_id' => $metricFilter->id,
        'organization_id' => $plan->organization_id,
        'values' => ['us'],
    ]);

    $payload = (new ChargeFilterSerializer($filter))->serialize();

    expect($payload['lago_id'])->toBe($filter->id)
        ->and($payload['charge_code'])->toBe('api')
        ->and($payload['invoice_display_name'])->toBe('US only')
        ->and($payload['properties'])->toBe(['amount' => '20'])
        ->and($payload['values'])->toBe(['region' => ['us']]);
})->group('ledger:ser:V1.ChargeFilterSerializer');

it('serializes a charge standalone with taxes omitted unless included', function (): void {
    $plan = serializerPlan();
    $metric = BillableMetric::factory()->create(['organization_id' => $plan->organization_id, 'code' => 'api']);
    $charge = Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metric->id,
        'code' => 'api',
        'charge_model' => 'package',
        'properties' => ['amount' => '100', 'free_units' => 10, 'package_size' => 10],
    ]);

    $without = (new ChargeSerializer($charge))->serialize();

    expect($without['charge_model'])->toBe('package')
        ->and(array_key_exists('taxes', $without))->toBeFalse();

    $with = (new ChargeSerializer($charge, ['includes' => ['taxes']]))->serialize();

    expect($with['taxes'])->toBe([]);
});
