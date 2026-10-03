<?php

declare(strict_types=1);

namespace App\Services\WalletTransactions;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\WalletCredit;
use App\Models\WalletTransaction;
use App\Services\Credits\CouponLock;
use App\Services\Wallets\Balance\DecreaseService;

/**
 * Port of Rails' WalletTransactions::VoidService
 * (app/services/wallet_transactions/void_service.rb) — writes an outbound
 * `voided` transaction and decrements the wallet balance, drawing the
 * consumption from a specific inbound grant or by priority.
 */
class VoidService extends BaseService
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly ?WalletCredit $walletCredit = null,
        private readonly ?WalletTransaction $inboundWalletTransaction = null,
        private readonly bool $voidRemaining = false,
        private readonly array $transactionParams = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet_transaction');
        $wallet = $this->wallet;
        $walletCredit = $this->walletCredit;

        if (! $this->voidRemaining && $walletCredit !== null && bccomp($walletCredit->creditAmount, '0', 5) === 0) {
            return $result;
        }

        if (! $this->valid($result)) {
            return $result;
        }

        $customer = $wallet->customer;

        // TODO(port): Rails uses Customers::LockService
        // (scope: :prepaid_credit); CouponLock carries the identical
        // advisory-lock key until that namespace is ported.
        CouponLock::withLock($customer, 'prepaid_credit', function () use ($wallet, $walletCredit, $result): void {
            $wallet->refresh();

            // Size the whole-remaining void under the lock so concurrent
            // consumption can't make it stale.
            $voidCredit = $this->voidRemaining
                ? WalletCredit::fromAmountCents($wallet, (int) ($this->inboundWalletTransaction?->fresh()?->remaining_amount_cents ?? 0))
                : $walletCredit;

            if (bccomp($voidCredit->creditAmount, '0', 5) !== 0) {
                $walletTransaction = CreateService::callBang(
                    wallet: $wallet,
                    walletCredit: $voidCredit,
                    transactionParams: [
                        ...$this->slicedParams(),
                        'transaction_type' => 'outbound',
                        'status' => 'settled',
                        'settled_at' => now(),
                        'transaction_status' => 'voided',
                        'billing_entity_id' => $this->inboundWalletTransaction?->billing_entity_id,
                    ],
                )->wallet_transaction;

                if ((bool) $wallet->traceable) {
                    TrackConsumptionService::callBang(
                        outboundWalletTransaction: $walletTransaction,
                        inboundWalletTransactionId: $this->inboundWalletTransaction?->id,
                    );
                }

                DecreaseService::call(wallet: $wallet, walletTransaction: $walletTransaction);

                $result->wallet_transaction = $walletTransaction;
            }
        });

        return $result;
    }

    /** @return array<string, mixed> */
    private function slicedParams(): array
    {
        return array_intersect_key($this->transactionParams, array_flip([
            'source',
            'metadata',
            'priority',
            'credit_note_id',
            'name',
        ]));
    }

    private function valid(BaseResult $result): bool
    {
        if (! (bool) $this->wallet->traceable) {
            return true;
        }

        if ($this->inboundWalletTransaction === null) {
            return true;
        }

        if ($this->voidRemaining) {
            return true;
        }

        $walletCredit = $this->walletCredit;

        if ($walletCredit !== null && $walletCredit->amountCents > (int) ($this->inboundWalletTransaction->remaining_amount_cents ?? 0)) {
            $result->singleValidationFailure('exceeds_remaining_transaction_amount', 'amount_cents');

            return false;
        }

        return true;
    }
}
