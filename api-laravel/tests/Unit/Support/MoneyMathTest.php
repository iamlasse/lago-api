<?php

declare(strict_types=1);

use App\Support\MoneyMath;

/**
 * Port of the rounding semantics the Rails billing pipeline relies on:
 * Ruby's `.round` rounds halves AWAY FROM ZERO. The proration
 * (days * amount / period) and every tax rounding in the invoice pipeline
 * go through this helper — half-cent cases are the contract.
 */
it('rounds positive halves away from zero (Ruby .round)', function (): void {
    expect(MoneyMath::round('0.5'))->toBe(1)
        ->and(MoneyMath::round('1.5'))->toBe(2)
        ->and(MoneyMath::round('2.5'))->toBe(3)
        ->and(MoneyMath::round('333.5'))->toBe(334);
});

it('rounds negative halves away from zero (Ruby .round)', function (): void {
    expect(MoneyMath::round('-0.5'))->toBe(-1)
        ->and(MoneyMath::round('-1.5'))->toBe(-2)
        ->and(MoneyMath::round('-2.5'))->toBe(-3);
});

it('rounds down below the half and up above it', function (): void {
    expect(MoneyMath::round('0.499999'))->toBe(0)
        ->and(MoneyMath::round('0.500001'))->toBe(1)
        ->and(MoneyMath::round('665.9999999999'))->toBe(666)
        ->and(MoneyMath::round('666.6666666666666'))->toBe(667);
});

it('accepts floats and ints without float artifacts', function (): void {
    expect(MoneyMath::round(2.5))->toBe(3)
        ->and(MoneyMath::round(3))->toBe(3)
        ->and(MoneyMath::round(100.5))->toBe(101);
});

it('rounds to a precision with halves away from zero (Ruby .round(2))', function (): void {
    expect(MoneyMath::roundTo('1.005', 2))->toBe('1.01')
        ->and(MoneyMath::roundTo('1.004', 2))->toBe('1.00')
        ->and(MoneyMath::roundTo('-1.005', 2))->toBe('-1.01');
});

it('ceil matches Ruby .ceil on decimals', function (): void {
    expect(MoneyMath::ceil('1.0001'))->toBe(2)
        ->and(MoneyMath::ceil('1.0'))->toBe(1)
        ->and(MoneyMath::ceil('3.0000001'))->toBe(4)
        ->and(MoneyMath::ceil('-1.2'))->toBe(-1);
});

it('floor matches Ruby .floor on decimals', function (): void {
    expect(MoneyMath::floor('1.9'))->toBe(1)
        ->and(MoneyMath::floor('2.0'))->toBe(2)
        ->and(MoneyMath::floor('-1.2'))->toBe(-2);
});

it('computes precise proration products', function (): void {
    // 1000 / 3 * 2 = 666.66... -> 667 (the classic monthly proration case)
    expect(MoneyMath::round(MoneyMath::mul((string) (1000 / 3), '2')))->toBe(667);

    // 5000 / 31 * 10 = 1612.90... -> 1613
    expect(MoneyMath::round((string) (5000 / 31 * 10)))->toBe(1613);
});

it('expands exponent notation from float stringification', function (): void {
    expect(MoneyMath::toDecimalString('1.0E-7'))->toBe('0.0000001')
        ->and(MoneyMath::toDecimalString('1E+3'))->toBe('1000')
        ->and(MoneyMath::round('1.0E-7'))->toBe(0);
});
