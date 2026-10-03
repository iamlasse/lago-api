<?php

declare(strict_types=1);

use App\Support\MoneyMath;
use App\Services\Fees\Amount;
use App\Services\Fees\TrueUp;
use App\Services\Fees\Deduction;
use App\Services\Fees\AmountsService;
use App\Services\ChargeModels\ChargeModelResult;

uses()->group('ledger:svc:Fees.AmountsService');

/**
 * Port of Rails' spec/services/fees/amounts_service_spec.rb — the
 * rounded/precise amount conversion behind every charge-model fee.
 *
 * Rails' ChargeModels::BaseService::Result leaves amount/unit_amount/units
 * unset until a charge model writes them; the ported ChargeModelResult
 * mirrors that with uninitialized typed properties (isset() is false).
 */
function amountsModelResult(string $value, string $units = '1'): ChargeModelResult
{
    $result = new ChargeModelResult();

    $result->setAmount($value);
    $result->setUnitAmount($value);
    $result->setUnits($units);

    return $result;
}

it('returns the base amount without deductions or a true-up when no options are given', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.3333'));

    expect($result->success())->toBeTrue();
    expect($result->amount->amountCents)->toBe(33);
    expect($result->amount->preciseAmountCents)->toBe('33.33');
    expect($result->amount->unitAmountCents)->toBe('33.33');
    expect($result->amount->preciseUnitAmount)->toBe('0.3333');
    expect($result->true_up_amount)->toBeNull();
});

it('rounds the total but leaves unit cents for the fee implicit truncation (currency matrix)', function (string $currency, string $input, int $rounded, string $precise, int $unitCents): void {
    $result = AmountsService::build($currency, amountsModelResult($input));

    expect($result->success())->toBeTrue();
    expect($result->amount->amountCents)->toBe($rounded);
    expect($result->amount->preciseAmountCents)->toBe($precise);
    expect($result->amount->unitAmountCents)->toBe($precise);
    expect($result->amount->preciseUnitAmount)->toBe($input);
    expect($result->true_up_amount)->toBeNull();

    // The fee's integer column truncates the fractional unit cents.
    expect((int) $result->amount->unitAmountCents)->toBe($unitCents);
})->with([
    ['USD', '0.3333', 33, '33.33', 33],
    ['USD', '0.336', 34, '33.6', 33],
    ['USD', '0.50125', 50, '50.125', 50],
    ['JPY', '0.3333', 0, '0.3333', 0],
    ['JPY', '0.336', 0, '0.336', 0],
    ['JPY', '0.50125', 1, '0.50125', 0],
    ['KWD', '0.3333', 333, '333.3', 333],
    ['KWD', '0.336', 336, '336', 336],
    ['KWD', '0.50125', 501, '501.25', 501],
]);

it('converts each model amount independently', function (): void {
    $model = amountsModelResult('0.3333', '3');
    $model->setAmount('0.9999');

    $result = AmountsService::build('USD', $model);

    expect($result->amount->amountCents)->toBe(100);
    expect($result->amount->preciseAmountCents)->toBe('99.99');
    expect($result->amount->unitAmountCents)->toBe('33.33');
    expect($result->amount->preciseUnitAmount)->toBe('0.3333');
});

it('retains the model flat amount and unit price with zero usage', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.3333', '0'));

    expect($result->amount->amountCents)->toBe(33);
    expect($result->amount->unitAmountCents)->toBe('33.33');
    expect($result->amount->preciseUnitAmount)->toBe('0.3333');
});

it('returns zero monetary fields for zero units and a zero amount', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0', '0'));

    expect($result->amount->amountCents)->toBe(0);
    expect($result->amount->preciseAmountCents)->toBe('0');
    expect($result->amount->unitAmountCents)->toBe('0');
    expect($result->amount->preciseUnitAmount)->toBe('0');
});

it('normalizes monetary fields without mutating the charge model result for negative units', function (): void {
    $model = amountsModelResult('0.3333', '-1');

    $result = AmountsService::build('USD', $model);

    expect($result->amount->amountCents)->toBe(0);
    expect($result->amount->preciseAmountCents)->toBe('0');
    expect($result->amount->unitAmountCents)->toBe('0');
    expect($result->amount->preciseUnitAmount)->toBe('0');

    expect($model->amount)->toBe('0.3333');
    expect($model->units)->toBe('-1');
});

it('normalizes monetary fields for a negative amount', function (): void {
    $model = amountsModelResult('-1', '-1');

    $result = AmountsService::build('USD', $model);

    expect($result->amount->amountCents)->toBe(0);
    expect($result->amount->preciseAmountCents)->toBe('0');
});

it('returns no amounts for an empty charge model result', function (): void {
    $model = new ChargeModelResult();

    $result = AmountsService::build('USD', $model);

    expect($result->success())->toBeTrue();
    expect($result->amount)->toBeNull();
    expect($result->true_up_amount)->toBeNull();
});

// -- Deductions ----------------------------------------------------------------

it('prorates and rounds the deduction without changing the unit price', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('1.006'), deduction: new Deduction(100, 2, 3));

    expect($result->amount->amountCents)->toBe(34);
    expect($result->amount->preciseAmountCents)->toBe('33.6');
    expect($result->amount->unitAmountCents)->toBe('100.6');
    expect($result->amount->preciseUnitAmount)->toBe('1.006');
});

it('retains the full rounded and precise totals for a zero deduction', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('1.006'), deduction: new Deduction(0, 2, 3));

    expect($result->amount->amountCents)->toBe(101);
    expect($result->amount->preciseAmountCents)->toBe('100.6');
});

it('clamps both totals independently to zero when the deduction exceeds the total', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.5'), deduction: new Deduction(100, 2, 3));

    expect($result->amount->amountCents)->toBe(0);
    expect($result->amount->preciseAmountCents)->toBe('0');
    expect($result->amount->unitAmountCents)->toBe('50');
    expect($result->amount->preciseUnitAmount)->toBe('0.5');
});

it('does not leave a negative precise total when only the precise total is below the deduction', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.666'), deduction: new Deduction(100, 2, 3));

    expect($result->amount->amountCents)->toBe(0);
    expect($result->amount->preciseAmountCents)->toBe('0');
});

it('retains the precise remainder when a precise remainder survives a zero rounded total', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.674'), deduction: new Deduction(100, 2, 3));

    expect($result->amount->amountCents)->toBe(0);
    expect($result->amount->preciseAmountCents)->toBe('0.4');
});

it('rounds the prorated deduction to integer cents', function (): void {
    expect((new Deduction(100, 2, 3))->proratedAmountCents())->toBe(67);
});

// -- True-ups ------------------------------------------------------------------

it('calculates amount and true-up from separate rounded and precise totals', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.3333'), trueUp: new TrueUp(100));

    expect($result->amount->amountCents)->toBe(33);
    expect($result->true_up_amount->amountCents)->toBe(67);
    expect($result->true_up_amount->preciseAmountCents)->toBe('66.67');
    expect($result->true_up_amount->unitAmountCents)->toBe('67');
    expect(MoneyMath::compare($result->true_up_amount->preciseUnitAmount, '0.6667'))->toBe(0);
});

it('uses grouped totals instead of the individual amount for the true-up', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.3333'), trueUp: new TrueUp(
        100,
        usedAmountCents: '66',
        usedPreciseAmountCents: '66.66',
    ));

    expect($result->amount->amountCents)->toBe(33);
    expect($result->true_up_amount->amountCents)->toBe(34);
    expect($result->true_up_amount->preciseAmountCents)->toBe('33.34');
});

it('does not create a true-up when rounded usage meets the minimum but precise usage does not', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.996'), trueUp: new TrueUp(100));

    expect($result->true_up_amount)->toBeNull();
});

it('does not create a true-up when usage exceeds the minimum', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('2'), trueUp: new TrueUp(100));

    expect($result->true_up_amount)->toBeNull();
});

it('does not create a true-up for a zero minimum', function (): void {
    $result = AmountsService::build('USD', amountsModelResult('0.3333'), trueUp: new TrueUp(0));

    expect($result->true_up_amount)->toBeNull();
});

it('returns only a true-up, not a synthetic base amount, with grouped usage totals', function (): void {
    $result = AmountsService::build('USD', new ChargeModelResult(), trueUp: new TrueUp(
        100,
        usedAmountCents: '66',
        usedPreciseAmountCents: '66.66',
    ));

    expect($result->amount)->toBeNull();
    expect($result->true_up_amount->amountCents)->toBe(34);
    expect($result->true_up_amount->preciseAmountCents)->toBe('33.34');
    expect($result->true_up_amount->unitAmountCents)->toBe('34');
    expect(MoneyMath::compare($result->true_up_amount->preciseUnitAmount, '0.3334'))->toBe(0);
});

it('does not round the minimum before computing the difference for a fractional prorated minimum', function (): void {
    $result = AmountsService::build('USD', new ChargeModelResult(), trueUp: new TrueUp(
        1000,
        15,
        31,
        usedAmountCents: '200',
        usedPreciseAmountCents: '200',
    ));

    expect($result->true_up_amount->amountCents)->toBe(284);
    // Rails computes the precise difference on a Float coerced into a
    // BigDecimal — the value carries float artifacts past the 12th digit.
    expect(MoneyMath::round($result->true_up_amount->preciseAmountCents))->toBe(284);
    expect(MoneyMath::round($result->true_up_amount->preciseUnitAmount))->toBe(3);
});

it('retains a zero-rounded true-up and its negative precise difference', function (): void {
    $result = AmountsService::build('USD', new ChargeModelResult(), trueUp: new TrueUp(
        100,
        1,
        16,
        usedAmountCents: '6',
        usedPreciseAmountCents: '6.6',
    ));

    expect($result->true_up_amount->amountCents)->toBe(0);
    expect(MoneyMath::compare($result->true_up_amount->preciseAmountCents, '-0.35'))->toBe(0);
});

it('uses the fiat subunit for the true-up precise unit amount', function (string $currency): void {
    $result = AmountsService::build($currency, new ChargeModelResult(), trueUp: new TrueUp(
        100,
        usedAmountCents: '66',
        usedPreciseAmountCents: '66.66',
    ));

    expect($result->true_up_amount->amountCents)->toBe(34);
    expect($result->true_up_amount->preciseAmountCents)->toBe('33.34');
    expect(MoneyMath::compare(
        $result->true_up_amount->preciseUnitAmount,
        MoneyMath::fdiv('33.34', (string) App\Support\Currency::subunitToUnit($currency)),
    ))->toBe(0);
})->with(['JPY', 'KWD']);

it('preserves fractional cents in the prorated minimum', function (): void {
    expect((new TrueUp(100, 2, 3))->proratedMinimumAmountCents())->toEqual(100 / 3 * 2);
});
