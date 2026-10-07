<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Wallet;

/**
 * Port of Rails' WalletCredit (app/models/wallet_credit.rb) — represents a
 * wallet credit in credits or in amount_cents. Use this class when
 * constructing wallet credits to make sure conversion between monetary
 * amounts and credit amounts remains consistent.
 *
 * All arithmetic is bcmath on decimal strings; the rounded `amount` carries
 * the currency's exponent digits (Money#round).
 */
final readonly class WalletCredit
{
    /**
     * The monetary amount, at the currency's exponent (Rails Money).
     */
    public string $amount;

    /**
     * The integer cent amount.
     */
    public int $amountCents;

    /**
     * The credit amount — for invoiceable credits, the monetary amount
     * converted back to credits ("only multiples of 1 cent should be
     * accepted").
     */
    public string $creditAmount;

    public function __construct(
        public Wallet $wallet,
        string|int|float $creditAmount,
        bool $invoiceable = true,
        ?int $amountCents = null,
    ) {
        $currency = $wallet->currencyForBalance();

        $amount = MoneyMath::roundTo(
            MoneyMath::mul((string) $creditAmount, (string) $wallet->rate_amount),
            $currency->exponent,
        );

        $this->amount = $amount;
        $this->amountCents = $amountCents ?? MoneyMath::round(
            MoneyMath::mul($amount, (string) $currency->subunit_to_unit),
        );

        $this->creditAmount = $invoiceable
            ? MoneyMath::fdiv($amount, (string) $wallet->rate_amount)
            : (string) $creditAmount;
    }

    /**
     * Rails: `WalletCredit.from_amount_cents` — convenience constructor for
     * when you need to construct a credit based on monetary amounts.
     */
    public static function fromAmountCents(Wallet $wallet, int $amountCents): self
    {
        $currency = $wallet->currencyForBalance();
        $amount = MoneyMath::fdiv((string) $amountCents, (string) $currency->subunit_to_unit);

        return new self(
            wallet: $wallet,
            creditAmount: MoneyMath::fdiv($amount, (string) $wallet->rate_amount),
            amountCents: $amountCents,
        );
    }

    /**
     * Rails: `WalletCredit.rounds_to_zero?` — true when a positive credit
     * amount still amounts to zero cents for the wallet's currency/rate.
     */
    public static function roundsToZero(Wallet $wallet, mixed $creditAmount): bool
    {
        if ($creditAmount === null || $creditAmount === '') {
            return false;
        }

        $floored = self::floorTo5($creditAmount);

        if (bccomp($floored, '0', 5) !== 1) {
            return false;
        }

        $credit = new self(wallet: $wallet, creditAmount: $floored);

        return $credit->amountCents === 0;
    }

    /**
     * BigDecimal#floor(5) — truncation toward negative infinity at 5
     * fractional digits.
     */
    private static function floorTo5(mixed $value): string
    {
        $dec = MoneyMath::toDecimalString($value);
        $truncated = bcadd($dec, '0', 5);

        if (str_starts_with($truncated, '-') && bccomp($truncated, $dec, 5) !== 0) {
            // A negative value with a dropped fraction floors one unit lower.
            return bcsub($truncated, '0.00001', 5);
        }

        return $truncated;
    }
}
