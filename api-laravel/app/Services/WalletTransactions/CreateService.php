<?php

declare(strict_types=1);

namespace App\Services\WalletTransactions;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\WalletCredit;
use App\Models\WalletTransaction;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionSource;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionCreditStatus;

/**
 * Port of Rails' WalletTransactions::CreateService
 * (app/services/wallet_transactions/create_service.rb) — the low-level
 * creator every transaction flows through (grants, purchases, voids).
 *
 * TODO(port): the payment_method param assignment
 * (payment_method_type / payment_method_id) — no PaymentMethod model yet;
 * the params are accepted and ignored.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly WalletCredit $walletCredit,
        private readonly array $transactionParams = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet_transaction');
        $wallet = $this->wallet;
        $walletCredit = $this->walletCredit;
        $transactionParams = $this->transactionParams;

        // Rails assigns the enum NAMES (params come in as "pending",
        // "inbound", ...); the columns store the integer positions — mapped
        // BEFORE fill (the enum casts reject scalar strings).
        foreach ([
            'status' => WalletTransactionStatus::class,
            'source' => WalletTransactionSource::class,
            'transaction_status' => WalletTransactionCreditStatus::class,
            'transaction_type' => WalletTransactionType::class,
        ] as $attribute => $enumClass) {
            if (($transactionParams[$attribute] ?? null) !== null) {
                $transactionParams[$attribute] = $enumClass::fromOption($transactionParams[$attribute])
                    ?? $transactionParams[$attribute];
            }
        }

        $transaction = new WalletTransaction(array_intersect_key($transactionParams, array_flip([
            'credit_note_id',
            'invoice_id',
            'invoice_requires_successful_payment',
            'name',
            'priority',
            'purchase_order_number',
            'settled_at',
            'source',
            'status',
            'transaction_type',
            'transaction_status',
            'voided_invoice_id',
        ])));

        $transaction->organization_id = $wallet->organization_id;
        $transaction->wallet_id = $wallet->id;
        $transaction->billing_entity_id = $this->billingEntityIdForSnapshot($wallet);
        $transaction->amount = $walletCredit->amount;
        $transaction->credit_amount = $walletCredit->creditAmount;
        $transaction->metadata = $transactionParams['metadata'] ?? [];
        $transaction->remaining_amount_cents = $this->initialRemainingAmountCents($wallet, $walletCredit);

        $errors = $transaction->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $transaction->save();

        // TODO(port): payment_method param assignment (see class docblock).

        $result->wallet_transaction = $transaction;

        return $result;
    }

    private function initialRemainingAmountCents(Wallet $wallet, WalletCredit $walletCredit): ?int
    {
        if (! (bool) $wallet->traceable) {
            return null;
        }

        if (($this->transactionParams['transaction_type'] ?? null) !== WalletTransactionType::Inbound->value
            && ($this->transactionParams['transaction_type'] ?? null) !== 'inbound') {
            return null;
        }

        if (($this->transactionParams['transaction_status'] ?? null) !== WalletTransactionCreditStatus::Granted->value
            && ($this->transactionParams['transaction_status'] ?? null) !== 'granted') {
            return null;
        }

        return $walletCredit->amountCents;
    }

    private function billingEntityIdForSnapshot(Wallet $wallet): ?string
    {
        return $this->transactionParams['billing_entity_id'] ?? $wallet->resolvedBillingEntity()?->id;
    }
}
