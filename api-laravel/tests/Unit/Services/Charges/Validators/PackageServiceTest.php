<?php

declare(strict_types=1);

require_once __DIR__.'/ValidatorTestCase.php';

use App\Services\Charges\Validators\PackageService;

/**
 * Port of spec/services/charges/validators/package_service_spec.rb.
 */
function packageValidator(array $properties): PackageService
{
    return new PackageService(chargeForValidation($properties, 'package'));
}

it('is valid with well-formed package properties', function () {
    $validator = packageValidator(['amount' => '100', 'free_units' => 10, 'package_size' => 10]);

    expect($validator->valid())->toBeTrue();
});

it('is invalid without amount', function () {
    $validator = packageValidator(['free_units' => 10, 'package_size' => 10]);

    expectPropertyError($validator, 'amount', 'invalid_amount');
});

it('is invalid when the amount is not numeric', function () {
    $validator = packageValidator(['amount' => 'foo', 'free_units' => 10, 'package_size' => 10]);

    expectPropertyError($validator, 'amount', 'invalid_amount');
});

it('is invalid with a negative amount', function () {
    $validator = packageValidator(['amount' => '-10', 'free_units' => 10, 'package_size' => 10]);

    expectPropertyError($validator, 'amount', 'invalid_amount');
});

it('is invalid without a package size', function () {
    $validator = packageValidator(['amount' => '100', 'free_units' => 10]);

    expectPropertyError($validator, 'package_size', 'invalid_package_size');
});

it('is invalid when the package size is not numeric', function () {
    $validator = packageValidator(['amount' => '100', 'free_units' => 10, 'package_size' => 'foo']);

    expectPropertyError($validator, 'package_size', 'invalid_package_size');
});

it('is invalid with a negative package size', function () {
    $validator = packageValidator(['amount' => '100', 'free_units' => 10, 'package_size' => -10]);

    expectPropertyError($validator, 'package_size', 'invalid_package_size');
});

it('is invalid with a zero package size', function () {
    $validator = packageValidator(['amount' => '100', 'free_units' => 10, 'package_size' => 0]);

    expectPropertyError($validator, 'package_size', 'invalid_package_size');
});

it('is invalid without free units', function () {
    $validator = packageValidator(['amount' => '100', 'package_size' => 10]);

    expectPropertyError($validator, 'free_units', 'invalid_free_units');
});

it('is invalid when the free units are not numeric', function () {
    $validator = packageValidator(['amount' => '100', 'free_units' => 'foo', 'package_size' => 10]);

    expectPropertyError($validator, 'free_units', 'invalid_free_units');
});

it('is invalid with negative free units', function () {
    $validator = packageValidator(['amount' => '100', 'free_units' => -10, 'package_size' => 10]);

    expectPropertyError($validator, 'free_units', 'invalid_free_units');
})->group('ledger:svc:Charges.Validators.PackageService');
