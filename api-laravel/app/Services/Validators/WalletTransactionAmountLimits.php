<?php

declare(strict_types=1);

namespace App\Services\Validators;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Support\WalletCredit;

use function bccomp;

/**
 * Port of Rails' Validators::WalletTransactionAmountLimitsValidator
 * (app/services/validators/wallet_transaction_amount_limits_validator.rb).
 *
 * NOTE: creditsAmount must be a string to go through
 * Validators\DecimalAmount (floats are rejected in billing math).
 */
final readonly class WalletTransactionAmountLimits
{
    public function __construct(
        private BaseResult $result,
        private Wallet $wallet,
        /** NOTE: must be a string (see DecimalAmount). */
        private mixed $creditsAmount,
        private mixed $ignoreValidation = false,
        private string $fieldName = 'paid_credits',
    ) {}

    public function raiseIfInvalid(): void
    {
        if ($this->valid()) {
            return;
        }

        // NOTE: if no error was set on the result, this won't raise.
        $this->result->raiseIfError();
    }

    public function valid(): bool
    {
        if (! $this->validPaidCreditsAmount()) {
            return false;
        }

        if (filter_var($this->ignoreValidation, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        if ($this->wallet->paid_top_up_min_amount_cents === null && $this->wallet->paid_top_up_max_amount_cents === null) {
            return true;
        }

        $walletCredit = new WalletCredit(
            wallet: $this->wallet,
            creditAmount: self::floorTo5((string) $this->creditsAmount),
        );

        if ($this->wallet->paid_top_up_min_amount_cents !== null && $walletCredit->amountCents < $this->wallet->paid_top_up_min_amount_cents) {
            $this->result->singleValidationFailure('amount_below_minimum', $this->fieldName);
        } elseif ($this->wallet->paid_top_up_max_amount_cents !== null && $walletCredit->amountCents > $this->wallet->paid_top_up_max_amount_cents) {
            $this->result->singleValidationFailure('amount_above_maximum', $this->fieldName);
        }

        return $this->result->success();
    }

    /** BigDecimal(credits_amount).floor(5). */
    private static function floorTo5(string $value): string
    {
        if (str_starts_with($value, '-') && bccomp($value, bcadd($value, '0', 5), 5) !== 0) {
            return bcsub(bcadd($value, '0', 5), '0.00001', 5);
        }

        return bcadd($value, '0', 5);
    }

    private function validPaidCreditsAmount(): bool
    {
        if (DecimalAmount::validPositiveAmount($this->creditsAmount)) {
            return true;
        }

        $this->result->singleValidationFailure($this->fieldName, 'invalid_amount');

        return false;
    }
}
