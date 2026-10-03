<?php

declare(strict_types=1);

namespace App\Services\WalletTransactions;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use App\Models\WalletTransactionConsumption;

use function bccomp;

/**
 * Port of Rails' WalletTransactions::TrackConsumptionService
 * (app/services/wallet_transactions/track_consumption_service.rb) — draws
 * an outbound movement from the wallet's inbound grants, either a specific
 * one or by consumption order (priority, granted-first, created_at),
 * recording a WalletTransactionConsumption per draw.
 */
class TrackConsumptionService extends BaseService
{
    public function __construct(
        private readonly WalletTransaction $outboundWalletTransaction,
        private readonly ?string $inboundWalletTransactionId = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        DB::transaction(function () use ($result): void {
            if ($this->inboundWalletTransactionId !== null) {
                $this->consumeFromSpecificInbound($result);
            } else {
                $this->consumeByPriority($result);
            }
        });

        return $result;
    }

    private function consumeFromSpecificInbound(BaseResult $result): void
    {
        $wallet = $this->wallet();

        $inbound = $wallet->walletTransactions()
            ->inbound()
            ->findOrFail($this->inboundWalletTransactionId);

        $amountCents = $this->outboundWalletTransaction->amountCents();

        if ($amountCents > (int) ($inbound->remaining_amount_cents ?? 0)) {
            $result->singleValidationFailure('exceeds_remaining_transaction_amount', 'amount_cents');

            return;
        }

        $this->createConsumption($inbound, $amountCents);
    }

    private function consumeByPriority(BaseResult $result): void
    {
        $amountCents = $this->outboundWalletTransaction->amountCents();
        $inbounds = $this->wallet()
            ->walletTransactions()
            ->availableInbound()
            ->inConsumptionOrder()
            ->get();

        $availableAmount = (int) $inbounds->sum(fn (WalletTransaction $inbound) => (int) $inbound->remaining_amount_cents);

        if ($amountCents > $availableAmount) {
            $result->singleValidationFailure('exceeds_available_amount', 'amount_cents');

            return;
        }

        $amountLeft = $amountCents;

        foreach ($inbounds as $inbound) {
            if (bccomp((string) $amountLeft, '0') <= 0) {
                break;
            }

            $consumeAmount = min((int) $inbound->remaining_amount_cents, $amountLeft);

            $this->createConsumption($inbound, $consumeAmount);
            $amountLeft -= $consumeAmount;
        }
    }

    private function createConsumption(WalletTransaction $inbound, int $amountCents): void
    {
        $wallet = $this->wallet();

        WalletTransactionConsumption::query()->create([
            'organization_id' => $wallet->organization_id,
            'inbound_wallet_transaction_id' => $inbound->id,
            'outbound_wallet_transaction_id' => $this->outboundWalletTransaction->id,
            'consumed_amount_cents' => $amountCents,
        ]);

        // this raises a DB error if the remaining_amount_cents goes below
        // zero (CHECK constraint).
        WalletTransaction::query()
            ->where('id', $inbound->id)
            ->decrement('remaining_amount_cents', $amountCents);
    }

    private function wallet(): Wallet
    {
        return $this->outboundWalletTransaction->wallet;
    }
}
