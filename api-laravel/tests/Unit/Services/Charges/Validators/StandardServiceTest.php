<?php

declare(strict_types=1);

require_once __DIR__.'/ValidatorTestCase.php';

use App\Services\Charges\Validators\StandardService;

/**
 * Port of spec/services/charges/validators/standard_service_spec.rb.
 */
it('is invalid when the amount is missing', function (): void {
    $validator = new StandardService(chargeForValidation([]));

    expectPropertyError($validator, 'amount', 'invalid_amount');
});

it('is invalid when the amount is not a number', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => 'Foo']));

    expectPropertyError($validator, 'amount', 'invalid_amount');
});

it('is invalid when the amount is negative', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => '-12']));

    expectPropertyError($validator, 'amount', 'invalid_amount');
});

it('is valid with an applicable amount', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => '12']));

    expect($validator->valid())->toBeTrue();
});

it('is invalid when the amount is a float, not a string', function (): void {
    // Rails only accepts amounts given as strings.
    $validator = new StandardService(chargeForValidation(['amount' => 12]));

    expectPropertyError($validator, 'amount', 'invalid_amount');
});

// -- pricing_group_keys property validation (shared example) -----------------

it('is valid with empty pricing_group_keys', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => '12', 'pricing_group_keys' => []]));

    expect($validator->valid())->toBeTrue();
});

it('is invalid when pricing_group_keys is not an array', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => '12', 'pricing_group_keys' => 'group']));

    expectPropertyError($validator, 'pricing_group_keys', 'invalid_type');
});

it('is invalid when pricing_group_keys is not a list of strings', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => '12', 'pricing_group_keys' => [12, 45]]));

    expectPropertyError($validator, 'pricing_group_keys', 'invalid_type');
});

it('is invalid when pricing_group_keys is an empty string', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => '12', 'pricing_group_keys' => '']));

    expectPropertyError($validator, 'pricing_group_keys', 'invalid_type');
});

it('accepts the legacy grouped_by property', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => '12', 'grouped_by' => []]));

    expect($validator->valid())->toBeTrue();
});

it('is invalid when the legacy grouped_by property is not an array', function (): void {
    $validator = new StandardService(chargeForValidation(['amount' => '12', 'grouped_by' => 'group']));

    expectPropertyError($validator, 'grouped_by', 'invalid_type');
})->group('ledger:svc:Charges.Validators.StandardService');

// -- presentation_group_keys property validation (shared example) ------------

it('is valid with valid presentation_group_keys', function (): void {
    $validator = new StandardService(chargeForValidation([
        'amount' => '12',
        'presentation_group_keys' => [
            ['value' => 'region'],
            ['value' => 'plan', 'options' => ['display_in_invoice' => true]],
        ],
    ]));

    expect($validator->valid())->toBeTrue();
});

it('is invalid when presentation_group_keys entries are not hashes', function (): void {
    $validator = new StandardService(chargeForValidation([
        'amount' => '12',
        'presentation_group_keys' => ['region'],
    ]));

    expectPropertyError($validator, 'presentation_group_keys', 'invalid_type');
});

it('is invalid when presentation_group_keys have unknown keys', function (): void {
    $validator = new StandardService(chargeForValidation([
        'amount' => '12',
        'presentation_group_keys' => [['value' => 'region', 'foo' => 'bar']],
    ]));

    expectPropertyError($validator, 'presentation_group_keys', 'invalid_type');
});

it('is invalid when presentation_group_keys values are duplicated', function (): void {
    $validator = new StandardService(chargeForValidation([
        'amount' => '12',
        'presentation_group_keys' => [['value' => 'region'], ['value' => 'region']],
    ]));

    expectPropertyError($validator, 'presentation_group_keys', 'value_is_duplicated');
});

it('is invalid with more than two presentation_group_keys', function (): void {
    $validator = new StandardService(chargeForValidation([
        'amount' => '12',
        'presentation_group_keys' => [['value' => 'a'], ['value' => 'b'], ['value' => 'c']],
    ]));

    expectPropertyError($validator, 'presentation_group_keys', 'too_many_keys');
})->group('ledger:svc:Charges.Validators.BaseService');
