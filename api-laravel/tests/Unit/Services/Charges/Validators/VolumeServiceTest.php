<?php

declare(strict_types=1);

require_once __DIR__.'/ValidatorTestCase.php';

use App\Services\Charges\Validators\VolumeService;

/**
 * Port of spec/services/charges/validators/volume_service_spec.rb — volume
 * ranges are adjacent-but-one: each range starts at the previous to_value + 1.
 */
function volumeRangesValidator(?array $ranges): VolumeService
{
    return new VolumeService(chargeForValidation(
        $ranges === null ? [] : ['volume_ranges' => $ranges],
        'volume',
    ));
}

it('is valid with well-formed volume ranges', function (): void {
    $validator = volumeRangesValidator([
        ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '1', 'flat_amount' => '0'],
        ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '2', 'flat_amount' => '100'],
    ]);

    expect($validator->valid())->toBeTrue();
});

it('ensures the presence of ranges', function (): void {
    $validator = volumeRangesValidator([]);

    expectPropertyError($validator, 'volume_ranges', 'missing_volume_ranges');
});

it('accepts a first range starting at 1 (to_value + 1 of the implicit 0)', function (): void {
    // Rails' valid_bounds? accepts from == next_from OR next_from + 1 — the
    // first iteration's next_from is 0, so 1 is a legal start.
    $validator = volumeRangesValidator([
        ['from_value' => 1, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '0'],
    ]);

    expect($validator->valid())->toBeTrue();
});

it('is invalid when ranges start below 0', function (): void {
    $validator = volumeRangesValidator([
        ['from_value' => -1, 'to_value' => 100],
    ]);

    expectPropertyError($validator, 'volume_ranges', 'invalid_volume_ranges');
});

it('is invalid when the next range does not start one unit after the previous end', function (): void {
    $validator = volumeRangesValidator([
        ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '0', 'flat_amount' => '0'],
        ['from_value' => 13, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '0'],
    ]);

    expectPropertyError($validator, 'volume_ranges', 'invalid_volume_ranges');
});

it('is invalid when ranges do not end at infinity', function (): void {
    $validator = volumeRangesValidator([
        ['from_value' => 0, 'to_value' => 100, 'per_unit_amount' => '0', 'flat_amount' => '0'],
    ]);

    expectPropertyError($validator, 'volume_ranges', 'invalid_volume_ranges');
});

it('is invalid with an invalid range amount', function (): void {
    $validator = volumeRangesValidator([
        ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => 'foo', 'flat_amount' => '0'],
    ]);

    expectPropertyError($validator, 'per_unit_amount', 'invalid_amount');
})->group('ledger:svc:Charges.Validators.VolumeService');
