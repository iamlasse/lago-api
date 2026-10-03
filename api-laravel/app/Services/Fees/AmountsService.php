<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Support\Currency;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\ChargeModels\ChargeModelResult;

/**
 * Port of Rails' Fees::AmountsService (app/services/fees/amounts_service.rb)
 * — converts a charge model result into the rounded/precise fee amount,
 * applying an optional prorated deduction (pay-in-advance "already billed"),
 * an optional true-up minimum and optional pricing-unit conversion.
 *
 * The Amount / Deduction / TrueUp / AppliedPricingUnit value objects mirror
 * the Rails Data.define twins (declared below this class in the same file —
 * they are private constants in Rails).
 *
 * TODO(port): PricingUnitUsage and
 * Charges::ApplyPayInAdvanceChargeModelService::Result are not ported yet —
 * the pay-in-advance branch of advanceAmount is unreachable until the
 * pay-in-advance result type lands, and `pricing_unit_usage` stays null.
 * The pricing-unit conversion branches are implemented against the
 * AppliedPricingUnit option so they light up with the pricing-unit features.
 */
class AmountsService extends \App\Services\BaseService
{
    public function __construct(
        private readonly string $currency,
        private readonly ChargeModelResult $chargeModelResult,
        private readonly AppliedPricingUnit $appliedPricingUnit,
        private readonly Deduction $deduction,
        private readonly TrueUp $trueUp,
    ) {
        parent::__construct();
    }

    /**
     * Rails defaults the options to their `none` values.
     */
    public static function build(
        string $currency,
        ChargeModelResult $chargeModelResult,
        ?AppliedPricingUnit $appliedPricingUnit = null,
        ?Deduction $deduction = null,
        ?TrueUp $trueUp = null,
    ): BaseResult {
        return (new self(
            currency: $currency,
            chargeModelResult: $chargeModelResult,
            appliedPricingUnit: $appliedPricingUnit ?? AppliedPricingUnit::none(),
            deduction: $deduction ?? Deduction::none(),
            trueUp: $trueUp ?? TrueUp::none(),
        ))->execute();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('amount', 'true_up_amount');

        // An empty model result means no base fee, not a zero-valued fee.
        // (Rails: charge_model_result.amount.nil?. The Laravel result object
        // types amount as string with a '0' default, so nil-ability is
        // carried by `units` — a fresh result has units === null, and every
        // charge model that writes an amount also writes units.)
        $amount = isset($this->chargeModelResult->amount) ? (string) $this->chargeModelResult->amount : null;
        $unitAmount = isset($this->chargeModelResult->unitAmount) ? (string) $this->chargeModelResult->unitAmount : null;
        $units = isset($this->chargeModelResult->units) ? (string) $this->chargeModelResult->units : null;

        if ($amount !== null && $units !== null) {
            if ($this->isAdvanceResult()) {
                $result->amount = $this->advanceAmount($amount, $unitAmount ?? '0');
            } elseif ($this->negative($units) || $this->negative($amount)) {
                $result->amount = $this->buildAmount('0', '0');
            } else {
                $result->amount = $this->buildAmount($amount, $unitAmount ?? '0');
            }

            if ($result->amount !== null && ! $this->deduction->isNone()) {
                $result->amount = $result->amount->withDeduction($this->deduction->proratedAmountCents());
            }
        }

        $result->true_up_amount = $this->trueUpAmount($result->amount);

        return $result;
    }

    /**
     * Rails accepts both ChargeModels::BaseService::Result and
     * Charges::ApplyPayInAdvanceChargeModelService::Result — the
     * pay-in-advance twin carries a separate precise_amount and minor-unit
     * totals.
     */
    private function isAdvanceResult(): bool
    {
        // TODO(port): Charges::ApplyPayInAdvanceChargeModelService::Result.
        return false;
    }

    private function negative(?string $value): bool
    {
        return $value !== null && MoneyMath::compare($value, '0') === -1;
    }

    /**
     * Rails: advance totals are already in minor units, with independently
     * computed precision (the unported pay-in-advance result keeps a
     * separate precise_amount; here precise mirrors the rounded total).
     */
    private function advanceAmount(string $amount, string $unitAmount): Amount
    {
        if (! $this->appliedPricingUnit->isNone()) {
            return $this->buildAmount(
                MoneyMath::fdiv($amount, $this->appliedPricingUnit->subunitToUnit()),
                $unitAmount,
            );
        }

        $subunit = (string) Currency::subunitToUnit($this->currency);

        return new Amount(
            amountCents: (int) MoneyMath::round($amount),
            preciseAmountCents: $amount,
            unitAmountCents: MoneyMath::mul($unitAmount, $subunit),
            preciseUnitAmount: $unitAmount,
        );
    }

    /** @param  Amount|null  $baseAmount  the computed base amount (null when the model result was empty) */
    private function trueUpAmount(?Amount $baseAmount): ?Amount
    {
        if ($this->trueUp->isNone()) {
            return null;
        }

        $minimum = $this->trueUp->proratedMinimumAmountCents();
        $used = $this->trueUp->usedAmountCents
            ?? ($baseAmount !== null ? (string) $baseAmount->amountCents : null);
        $preciseUsed = $this->trueUp->usedPreciseAmountCents
            ?? ($baseAmount !== null ? $baseAmount->preciseAmountCents : null);

        if ($used === null || $preciseUsed === null) {
            return null;
        }

        if (MoneyMath::compare($used, (string) $minimum) !== -1) {
            return null;
        }

        // NOTE: Rails computes the precise difference on floats coerced into
        // BigDecimal (minimum is a Float) — the decimal string keeps those
        // float artifacts, which is what downstream consumers round anyway.
        $difference = MoneyMath::sub($minimum, $used);
        $preciseDifference = MoneyMath::sub($minimum, $preciseUsed);

        if (! $this->appliedPricingUnit->isNone()) {
            // Minimum and used totals are in pricing-unit cents, not fiat cents.
            $subunit = $this->appliedPricingUnit->subunitToUnit();

            return $this->buildAmount(
                MoneyMath::fdiv($difference, $subunit),
                MoneyMath::fdiv($preciseDifference, $subunit),
            );
        }

        // True-ups retain separate rounded and precise totals across grouped fees.
        return new Amount(
            amountCents: MoneyMath::round($difference),
            preciseAmountCents: $preciseDifference,
            unitAmountCents: (string) MoneyMath::round($difference),
            preciseUnitAmount: MoneyMath::fdiv($preciseDifference, (string) Currency::subunitToUnit($this->currency)),
        );
    }

    /**
     * Rails build_amount without pricing units:
     *   amount_cents         = amount.round(currency.exponent) * currency.subunit_to_unit
     *   precise_amount_cents = amount * currency.subunit_to_unit
     *   unit_amount_cents    = unit_amount * currency.subunit_to_unit
     *   precise_unit_amount  = unit_amount
     *
     * TODO(port): the pricing-unit branch (PricingUnitUsage
     * .build_from_fiat_amounts + to_fiat_currency_cents) — pricing units
     * are not ported; the plain fiat math below applies.
     */
    private function buildAmount(string $amount, string $unitAmount): Amount
    {
        $subunit = (string) Currency::subunitToUnit($this->currency);

        return new Amount(
            amountCents: (int) MoneyMath::mul(MoneyMath::roundTo($amount, Currency::exponent($this->currency)), $subunit),
            preciseAmountCents: MoneyMath::mul($amount, $subunit),
            unitAmountCents: MoneyMath::mul($unitAmount, $subunit),
            preciseUnitAmount: $unitAmount,
        );
    }
}

/**
 * Port of the private `Amount` Data.define — the rounded and precise fee
 * totals. unit_amount_cents keeps its fractional part here (the fee's
 * integer column truncates it implicitly, exactly like Rails).
 */
final class Amount
{
    public function __construct(
        public readonly int $amountCents,
        public string $preciseAmountCents,
        public string $unitAmountCents,
        public string $preciseUnitAmount,
    ) {
        // bcmath returns fixed-scale strings ("33.330000000000000") — keep
        // the normalized decimal form the Ruby BigDecimal carries.
        $this->preciseAmountCents = MoneyMath::toDecimalString($this->preciseAmountCents);
        $this->unitAmountCents = MoneyMath::toDecimalString($this->unitAmountCents);
        $this->preciseUnitAmount = MoneyMath::toDecimalString($this->preciseUnitAmount);
    }

    public function withDeduction(int $deduction): self
    {
        $amount = max(MoneyMath::round(MoneyMath::sub((string) $this->amountCents, (string) $deduction)), 0);
        $precise = MoneyMath::toDecimalString(MoneyMath::sub($this->preciseAmountCents, (string) $deduction));

        return new self(
            $amount,
            MoneyMath::compare($precise, '0') === -1 ? '0' : $precise,
            $this->unitAmountCents,
            $this->preciseUnitAmount,
        );
    }

    /** Rails `to_h` — the fee attribute mapping. */
    public function toFeeAttributes(): array
    {
        return [
            'amount_cents' => $this->amountCents,
            'precise_amount_cents' => $this->preciseAmountCents,
            'unit_amount_cents' => $this->unitAmountCents,
            'precise_unit_amount' => $this->preciseUnitAmount,
        ];
    }
}

/**
 * Port of the `Deduction` Data.define — an already-billed amount prorated
 * over the billed/period days.
 */
final class Deduction
{
    public function __construct(
        public readonly ?int $amountCents,
        public readonly int $billedDays = 1,
        public readonly int $periodDays = 1,
    ) {}

    public static function none(): self
    {
        return new self(amountCents: null);
    }

    public function isNone(): bool
    {
        return $this->amountCents === null;
    }

    /** Rails: (amount_cents * billed_days.to_f / period_days).round. */
    public function proratedAmountCents(): int
    {
        return MoneyMath::round(MoneyMath::fdiv(
            (string) ((int) $this->amountCents * $this->billedDays),
            (string) $this->periodDays,
        ));
    }
}

/**
 * Port of the `TrueUp` Data.define — the prorated minimum commitment and the
 * (optionally grouped) usage to compare against.
 */
final class TrueUp
{
    public function __construct(
        public readonly ?int $minimumAmountCents,
        public readonly int $billedDays = 1,
        public readonly int $periodDays = 1,
        public readonly ?string $usedAmountCents = null,
        public readonly ?string $usedPreciseAmountCents = null,
    ) {}

    public static function none(): self
    {
        return new self(minimumAmountCents: null);
    }

    public function isNone(): bool
    {
        return $this->minimumAmountCents === null;
    }

    /** Rails: minimum_amount_cents.fdiv(period_days) * billed_days — a float. */
    public function proratedMinimumAmountCents(): float
    {
        return ((int) $this->minimumAmountCents) / $this->periodDays * $this->billedDays;
    }
}

/**
 * Port of the `AppliedPricingUnit` Data.define — the pricing unit a charge
 * bills in, with its conversion rate into the fiat currency.
 *
 * TODO(port): the PricingUnit model does not exist yet; this is a
 * duck-typed stand-in carrying subunitToUnit(). fromAppliedPricingUnit
 * lands with the pricing-unit features.
 */
final class AppliedPricingUnit
{
    public function __construct(
        public readonly ?object $pricingUnit,
        public readonly ?string $conversionRate,
    ) {}

    public static function none(): self
    {
        return new self(pricingUnit: null, conversionRate: null);
    }

    public static function fromPricingUnit(?object $pricingUnit, ?string $conversionRate): self
    {
        if ($pricingUnit === null) {
            return self::none();
        }

        return new self(pricingUnit: $pricingUnit, conversionRate: $conversionRate);
    }

    public function isNone(): bool
    {
        return $this->pricingUnit === null;
    }

    /** Rails: pricing_unit.subunit_to_unit.to_d. */
    public function subunitToUnit(): string
    {
        return MoneyMath::toDecimalString((string) $this->pricingUnit->subunitToUnit());
    }
}
