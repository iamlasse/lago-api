<?php

declare(strict_types=1);

namespace App\Services\WalletTransactions;

use App\Models\Wallet;
use App\Models\Customer;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Support\WalletCredit;
use App\Services\Validators\Metadata;
use App\Services\Validators\DecimalAmount;

/**
 * Port of Rails' WalletTransactions::ValidateService
 * (app/services/wallet_transactions/validate_service.rb).
 *
 * Not ported (TODO(port)): payment_method validation
 * (PaymentMethods::ValidateService — no PaymentMethod model yet); args are
 * accepted and skipped.
 */
class ValidateService extends BaseValidator
{
    /** Rails: MAX_AMOUNT = 10**25 - 1. */
    private const MAX_AMOUNT = '9999999999999999999999999';

    private const MAX_METADATA_KEYS = 15;

    public function __construct(
        BaseResult $result,
        protected array $args,
    ) {
        parent::__construct($result);
    }

    public function valid(): bool
    {
        $this->validWallet();

        if (($this->args['paid_credits'] ?? null) !== null) {
            $this->validPaidCreditsAmount();
        }

        if (($this->args['granted_credits'] ?? null) !== null) {
            $this->validGrantedCreditsAmount();
        }

        if (($this->args['voided_credits'] ?? null) !== null && $this->result->current_wallet !== null) {
            $this->validVoidedCreditsAmount();
        }

        if (($this->args['voided_transaction_id'] ?? null) !== null && $this->result->current_wallet !== null) {
            $this->validVoidedTransaction();
        }

        if (($this->args['metadata'] ?? null) !== null) {
            $this->validMetadata();
        }

        if (($this->args['name'] ?? null) !== null) {
            $this->validName();
        }

        // TODO(port): valid_payment_method? (PaymentMethods::ValidateService).

        if ($this->errors()) {
            $this->result->validationFailure($this->messages());

            return false;
        }

        return true;
    }

    private function validAmount(mixed $amount): bool
    {
        return DecimalAmount::validAmount($amount)
            && bccomp(MoneyMath::toDecimalString($amount), '0', 20) >= 0
            && bccomp(MoneyMath::toDecimalString($amount), self::MAX_AMOUNT, 20) <= 0;
    }

    private function validWallet(): bool
    {
        $walletQuery = Wallet::query()->where('id', $this->args['wallet_id'] ?? null);

        if (($this->args['customer'] ?? null) instanceof Customer) {
            $walletQuery->where('customer_id', $this->args['customer']->id);
        } elseif (($this->args['organization'] ?? null) !== null) {
            $walletQuery->where('organization_id', $this->args['organization']->id);
        } elseif (($this->args['organization_id'] ?? null) !== null) {
            $walletQuery->where('organization_id', $this->args['organization_id']);
        }

        $wallet = $walletQuery->first();

        $this->result->current_wallet = $wallet;

        if ($wallet === null) {
            return $this->addError('wallet_id', 'wallet_not_found');
        }

        if ($wallet->isTerminated()) {
            return $this->addError('wallet_id', 'wallet_is_terminated');
        }

        return true;
    }

    private function validPaidCreditsAmount(): bool
    {
        if (! $this->validAmount($this->args['paid_credits'])) {
            $this->addError('paid_credits', 'invalid_paid_credits');
            $this->addError('paid_credits', 'invalid_amount');

            return false;
        }

        return $this->validMinimumMonetaryValue($this->args['paid_credits'], 'paid_credits');
    }

    private function validGrantedCreditsAmount(): bool
    {
        if (! $this->validAmount($this->args['granted_credits'])) {
            $this->addError('granted_credits', 'invalid_granted_credits');
            $this->addError('granted_credits', 'invalid_amount');

            return false;
        }

        return $this->validMinimumMonetaryValue($this->args['granted_credits'], 'granted_credits');
    }

    private function validMinimumMonetaryValue(mixed $credits, string $field): bool
    {
        if ($this->result->current_wallet === null) {
            return true;
        }

        if (! WalletCredit::roundsToZero($this->result->current_wallet, $credits)) {
            return true;
        }

        return $this->addError($field, 'amount_rounds_to_zero');
    }

    private function validVoidedCreditsAmount(): bool
    {
        $voidedCredits = $this->args['voided_credits'];

        if (! $this->validAmount($voidedCredits)) {
            $this->addError('voided_credits', 'invalid_voided_credits');
            $this->addError('voided_credits', 'invalid_amount');

            return false;
        }

        /** @var Wallet $wallet */
        $wallet = $this->result->current_wallet;

        if (bccomp(MoneyMath::toDecimalString($voidedCredits), (string) $wallet->credits_balance, 20) === 1) {
            return $this->addError('voided_credits', 'insufficient_credits');
        }

        return true;
    }

    private function validVoidedTransaction(): bool
    {
        /** @var Wallet $wallet */
        $wallet = $this->result->current_wallet;

        // Targeting a specific grant relies on the per-transaction ledger,
        // which only traceable wallets keep.
        if (! (bool) $wallet->traceable) {
            return $this->addError('voided_transaction_id', 'wallet_not_traceable');
        }

        $transaction = $wallet->walletTransactions()
            ->inbound()
            ->where('id', $this->args['voided_transaction_id'])
            ->first();

        if ($transaction === null) {
            return $this->addError('voided_transaction_id', 'wallet_transaction_not_found');
        }

        if ((int) ($transaction->remaining_amount_cents ?? 0) <= 0) {
            return $this->addError('voided_transaction_id', 'no_remaining_amount');
        }

        $this->result->voided_wallet_transaction = $transaction;

        return true;
    }

    private function validMetadata(): bool
    {
        $validator = new Metadata($this->args['metadata'], ['max_keys' => self::MAX_METADATA_KEYS]);

        if (! $validator->valid()) {
            foreach ($validator->errors as $field => $errorCode) {
                $this->addError($field, $errorCode);
            }

            return false;
        }

        return true;
    }

    private function validName(): void
    {
        $name = $this->args['name'];

        if (($name ?? '') === '') {
            return;
        }

        if (! is_string($name)) {
            $this->addError('name', 'invalid_value');

            return;
        }

        if (mb_strlen($name) > 255) {
            $this->addError('name', 'too_long');

            return;
        }
    }
}
