<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\WalletTransactionConsumption as WalletTransactionConsumptionModel;

/**
 * Field resolvers for the frozen SDL's `WalletTransactionConsumption` type
 * (port of Rails' Types::WalletTransactionConsumptions::Object).
 */
class WalletTransactionConsumption
{
    /** Rails: field :amount_cents, method: :consumed_amount_cents. */
    public function amountCents(WalletTransactionConsumptionModel $root): int
    {
        return (int) $root->consumed_amount_cents;
    }

    /**
     * Rails: `credit_amount` — the consumed cents converted to wallet
     * credits through the wallet's currency and rate.
     */
    public function creditAmount(WalletTransactionConsumptionModel $root): string
    {
        $wallet = $root->outboundWalletTransaction?->wallet
            ?? $root->inboundWalletTransaction?->wallet;

        $subunitToUnit = $wallet?->currencyForBalance()->subunit_to_unit ?: 100;
        $rateAmount = (float) ($wallet?->rate_amount ?: 1);

        return (string) (($root->consumed_amount_cents ?: 0) / $subunitToUnit / $rateAmount);
    }

    /** Rails: field :wallet_transaction — the outbound movement. */
    public function walletTransaction(WalletTransactionConsumptionModel $root): object
    {
        return $root->outboundWalletTransaction;
    }
}
