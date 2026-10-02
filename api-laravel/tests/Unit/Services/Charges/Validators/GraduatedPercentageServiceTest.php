<?php

declare(strict_types=1);

require_once __DIR__.'/ValidatorTestCase.php';

use Database\Factories\BillableMetricFactory;
use App\Services\Charges\Validators\GraduatedPercentageService;

/**
 * Port of spec/services/charges/validators/graduated_percentage_service_spec.rb.
 *
 * graduated_percentage charges require a `latest_agg` billable metric and a
 * premium license.
 */
function graduatedPercentageValidator(array $properties): GraduatedPercentageService
{
    $metric = metricForValidation(BillableMetricFactory::LATEST_AGG);

    return new GraduatedPercentageService(chargeWithMetricForValidation($properties, $metric));
}

function validGraduatedPercentageRanges(): array
{
    return [
        ['from_value' => 0, 'to_value' => 10, 'rate' => '0.1', 'flat_amount' => '0'],
        ['from_value' => 11, 'to_value' => null, 'rate' => '0.2', 'flat_amount' => '10'],
    ];
}

it('is valid with well-formed ranges on a latest_agg metric', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => validGraduatedPercentageRanges(),
    ]);

    expect($validator->valid())->toBeTrue();
});

it('is invalid when the billable metric is not latest_agg', function () {
    $metric = metricForValidation(BillableMetricFactory::SUM_AGG);

    $validator = new GraduatedPercentageService(chargeWithMetricForValidation([
        'graduated_percentage_ranges' => validGraduatedPercentageRanges(),
    ], $metric));

    expectPropertyError($validator, 'billable_metric', 'invalid_value');
});

it('ensures the presence of ranges', function () {
    $validator = graduatedPercentageValidator([]);

    expectPropertyError($validator, 'graduated_percentage_ranges', 'missing_graduated_percentage_ranges');
});

it('fails validation instead of raising when the ranges key is absent', function () {
    $validator = graduatedPercentageValidator(['foo' => 'bar']);

    expectPropertyError($validator, 'graduated_percentage_ranges', 'missing_graduated_percentage_ranges');
});

it('is invalid when ranges do not start at 0', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => [
            ['from_value' => -1, 'to_value' => 100, 'rate' => '0.1', 'flat_amount' => '0'],
        ],
    ]);

    expectPropertyError($validator, 'graduated_percentage_ranges', 'invalid_graduated_percentage_ranges');
});

it('is invalid when ranges do not end at infinity', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => [
            ['from_value' => 0, 'to_value' => 100, 'rate' => '0.1', 'flat_amount' => '0'],
        ],
    ]);

    expectPropertyError($validator, 'graduated_percentage_ranges', 'invalid_graduated_percentage_ranges');
});

it('is invalid when ranges have holes', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => [
            ['from_value' => 0, 'to_value' => 100, 'rate' => '0.1', 'flat_amount' => '0'],
            ['from_value' => 120, 'to_value' => 100, 'rate' => '0.1', 'flat_amount' => '0'],
        ],
    ]);

    expectPropertyError($validator, 'graduated_percentage_ranges', 'invalid_graduated_percentage_ranges');
});

it('is invalid when ranges are overlapping', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => [
            ['from_value' => 0, 'to_value' => 100, 'rate' => '0.1', 'flat_amount' => '0'],
            ['from_value' => 90, 'to_value' => 100, 'rate' => '0.1', 'flat_amount' => '0'],
        ],
    ]);

    expectPropertyError($validator, 'graduated_percentage_ranges', 'invalid_graduated_percentage_ranges');
});

it('is invalid with no range rate', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => [
            ['from_value' => 0, 'to_value' => null, 'rate' => null, 'flat_amount' => '0'],
        ],
    ]);

    expectPropertyError($validator, 'rate', 'invalid_rate');
});

it('is invalid with a non-numeric range rate', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => [
            ['from_value' => 0, 'to_value' => null, 'rate' => 'foo', 'flat_amount' => '0'],
        ],
    ]);

    expectPropertyError($validator, 'rate', 'invalid_rate');
});

it('is invalid with a negative range rate', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => [
            ['from_value' => 0, 'to_value' => null, 'rate' => '-0.1', 'flat_amount' => '0'],
        ],
    ]);

    expectPropertyError($validator, 'rate', 'invalid_rate');
});

it('is invalid with no range flat amount', function () {
    $validator = graduatedPercentageValidator([
        'graduated_percentage_ranges' => [
            ['from_value' => 0, 'to_value' => null, 'rate' => '0.1', 'flat_amount' => null],
        ],
    ]);

    expectPropertyError($validator, 'flat_amount', 'invalid_amount');
})->group('ledger:svc:Charges.Validators.GraduatedPercentageService');
