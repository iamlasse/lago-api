<?php

declare(strict_types=1);

require_once __DIR__.'/ValidatorTestCase.php';

use Database\Factories\BillableMetricFactory;
use App\Services\Charges\Validators\PercentageService;

/**
 * Port of spec/services/charges/validators/percentage_service_spec.rb.
 *
 * Percentage charges require a `latest_agg` billable metric — the default
 * (sum) metric exercises the invalid_value branch, and latest_agg is wired
 * with setRelation for the valid paths.
 */
function percentageValidator(array $properties, bool $latestMetric = false): PercentageService
{
    $metric = metricForValidation(
        $latestMetric ? BillableMetricFactory::LATEST_AGG : BillableMetricFactory::SUM_AGG,
    );

    return new PercentageService(chargeWithMetricForValidation($properties, $metric));
}

it('is invalid when the billable metric is not latest_agg', function (): void {
    $validator = percentageValidator(['rate' => '0.25', 'fixed_amount' => '2']);

    expectPropertyError($validator, 'billable_metric', 'invalid_value');
});

it('is valid with a latest_agg billable metric and a rate', function (): void {
    $validator = percentageValidator(['rate' => '0.25', 'fixed_amount' => '2'], latestMetric: true);

    expect($validator->valid())->toBeTrue();
});

it('is invalid without a rate', function (): void {
    $validator = percentageValidator(['fixed_amount' => '2'], latestMetric: true);

    expectPropertyError($validator, 'rate', 'invalid_rate');
});

it('is invalid when the rate is not a string', function (): void {
    $validator = percentageValidator(['rate' => 0.25, 'fixed_amount' => '2'], latestMetric: true);

    expectPropertyError($validator, 'rate', 'invalid_rate');
});

it('is invalid when the rate cannot be converted to numeric format', function (): void {
    $validator = percentageValidator(['rate' => 'foo'], latestMetric: true);

    expectPropertyError($validator, 'rate', 'invalid_rate');
});

it('is invalid with a negative rate', function (): void {
    $validator = percentageValidator(['rate' => '-0.3'], latestMetric: true);

    expectPropertyError($validator, 'rate', 'invalid_rate');
});

it('is valid with a zero rate', function (): void {
    $validator = percentageValidator(['rate' => '0'], latestMetric: true);

    expect($validator->valid())->toBeTrue();
});

it('is invalid when free_units_per_events is not an integer', function (): void {
    $validator = percentageValidator(['rate' => '0.25', 'free_units_per_events' => 'foo'], latestMetric: true);

    expectPropertyError($validator, 'free_units_per_events', 'invalid_free_units_per_events');
});

it('is invalid when free_units_per_events is negative', function (): void {
    $validator = percentageValidator(['rate' => '0.25', 'free_units_per_events' => -3], latestMetric: true);

    expectPropertyError($validator, 'free_units_per_events', 'invalid_free_units_per_events');
});

it('is invalid when the fixed amount and free_units_per_total_aggregation are not numeric', function (): void {
    $validator = percentageValidator([
        'rate' => '0.25',
        'fixed_amount' => 'bla',
        'free_units_per_total_aggregation' => 'bla',
    ], latestMetric: true);

    expectPropertyError($validator, 'fixed_amount', 'invalid_fixed_amount');
    expectPropertyError($validator, 'free_units_per_total_aggregation', 'invalid_free_units_per_total_aggregation');
});

it('is invalid when the fixed amount is not a string', function (): void {
    $validator = percentageValidator(['rate' => '0.25', 'fixed_amount' => 2], latestMetric: true);

    expectPropertyError($validator, 'fixed_amount', 'invalid_fixed_amount');
});

it('is valid without the optional properties', function (): void {
    $validator = percentageValidator(['rate' => '0.25'], latestMetric: true);

    expect($validator->valid())->toBeTrue();
});

// -- premium: per-transaction min/max (LAGO_LICENSE unset = non-premium) -----

it('ignores per_transaction amounts without a premium license', function (): void {
    // Non-premium Rails skips the per-transaction validation entirely —
    // a non-string min amount does not fail.
    $validator = percentageValidator([
        'rate' => '0.25',
        'fixed_amount' => '2',
        'per_transaction_min_amount' => 2,
    ], latestMetric: true);

    expect($validator->valid())->toBeTrue();
});

it('is valid when per_transaction_max is higher than per_transaction_min', function (): void {
    $validator = percentageValidator([
        'rate' => '0.25',
        'fixed_amount' => '2',
        'per_transaction_min_amount' => '2',
        'per_transaction_max_amount' => '3',
    ], latestMetric: true);

    expect($validator->valid())->toBeTrue();
})->group('ledger:svc:Charges.Validators.PercentageService');
