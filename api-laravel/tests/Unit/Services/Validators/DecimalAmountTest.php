<?php

declare(strict_types=1);

use App\Services\Validators\DecimalAmount;

/**
 * Port of spec/services/validators/decimal_amount_service_spec.rb.
 */
it('accepts positive, zero and decimal numeric strings', function () {
    expect(DecimalAmount::validAmount('12'))->toBeTrue()
        ->and(DecimalAmount::validAmount('0'))->toBeTrue()
        ->and(DecimalAmount::validAmount('0.0'))->toBeTrue()
        ->and(DecimalAmount::validAmount('12.5'))->toBeTrue()
        ->and(DecimalAmount::validAmount('0.0001'))->toBeTrue();
});

it('rejects negative amounts but accepts them for the positive check as invalid', function () {
    expect(DecimalAmount::validAmount('-12'))->toBeFalse()
        ->and(DecimalAmount::validPositiveAmount('-12'))->toBeFalse();
});

it('rejects non-numeric and non-string input', function () {
    expect(DecimalAmount::validAmount('Foo'))->toBeFalse()
        ->and(DecimalAmount::validAmount(null))->toBeFalse()
        // Rails: valid_decimal? returns false unless amount.is_a?(String).
        ->and(DecimalAmount::validAmount(12))->toBeFalse()
        ->and(DecimalAmount::validAmount(12.5))->toBeFalse()
        ->and(DecimalAmount::validAmount(''))->toBeFalse();
});

it('accepts scientific notation like BigDecimal', function () {
    expect(DecimalAmount::validAmount('1e3'))->toBeTrue()
        ->and(DecimalAmount::canonical('1e3'))->toBe('1000')
        ->and(DecimalAmount::canonical('1.5e-2'))->toBe('0.015');
});

it('rejects whitespace-padded strings like BigDecimal', function () {
    expect(DecimalAmount::validAmount(' 12'))->toBeFalse();
});

it('requires strict positivity for validPositiveAmount', function () {
    expect(DecimalAmount::validPositiveAmount('0'))->toBeFalse()
        ->and(DecimalAmount::validPositiveAmount('0.0001'))->toBeTrue()
        ->and(DecimalAmount::validPositiveAmount('foo'))->toBeFalse();
});
