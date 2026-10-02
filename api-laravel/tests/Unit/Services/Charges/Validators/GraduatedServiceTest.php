<?php

declare(strict_types=1);

require_once __DIR__.'/ValidatorTestCase.php';

use App\Services\Charges\Validators\GraduatedService;

/**
 * Port of spec/services/charges/validators/graduated_service_spec.rb.
 */
function graduatedRangesValidator(?array $ranges): GraduatedService
{
    return new GraduatedService(chargeForValidation(
        $ranges === null ? [] : ['graduated_ranges' => $ranges],
        'graduated',
    ));
}

it('is valid with well-formed graduated ranges', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '1', 'flat_amount' => '0'],
        ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '2', 'flat_amount' => '100'],
    ]);

    expect($validator->valid())->toBeTrue();
});

it('ensures the presence of ranges when empty', function (): void {
    $validator = graduatedRangesValidator([]);

    expectPropertyError($validator, 'graduated_ranges', 'missing_graduated_ranges');
});

it('ensures the presence of ranges when nil', function (): void {
    $validator = graduatedRangesValidator(null);

    expectPropertyError($validator, 'graduated_ranges', 'missing_graduated_ranges');
});

it('is invalid when ranges do not start at 0', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => -1, 'to_value' => 100],
    ]);

    expectPropertyError($validator, 'graduated_ranges', 'invalid_graduated_ranges');
});

it('is invalid when ranges do not end at infinity', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => 0, 'to_value' => 100],
    ]);

    expectPropertyError($validator, 'graduated_ranges', 'invalid_graduated_ranges');
});

it('is invalid when ranges have holes', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => 0, 'to_value' => 100],
        ['from_value' => 120, 'to_value' => 100],
    ]);

    expectPropertyError($validator, 'graduated_ranges', 'invalid_graduated_ranges');
});

it('is invalid when ranges are overlapping', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => 0, 'to_value' => 100],
        ['from_value' => 90, 'to_value' => 100],
    ]);

    expectPropertyError($validator, 'graduated_ranges', 'invalid_graduated_ranges');
});

it('is invalid with no range per unit amount', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => null, 'flat_amount' => '0'],
    ]);

    expectPropertyError($validator, 'per_unit_amount', 'invalid_amount');
});

it('is invalid with a non-numeric range per unit amount', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => 'foo', 'flat_amount' => '0'],
    ]);

    expectPropertyError($validator, 'per_unit_amount', 'invalid_amount');
});

it('is invalid with a negative range per unit amount', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => '-12', 'flat_amount' => '0'],
    ]);

    expectPropertyError($validator, 'per_unit_amount', 'invalid_amount');
});

it('is invalid with no range flat amount', function (): void {
    $validator = graduatedRangesValidator([
        ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => null],
    ]);

    expectPropertyError($validator, 'flat_amount', 'invalid_amount');
})->group('ledger:svc:Charges.Validators.GraduatedService');
