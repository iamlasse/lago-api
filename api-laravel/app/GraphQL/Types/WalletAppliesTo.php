<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Wallet as WalletModel;

/**
 * Field resolvers for the frozen SDL's `WalletAppliesTo` type — the port of
 * Rails' Types::Wallets::AppliesTo, resolved against the wallet itself
 * (Rails declares the field `method: :itself`).
 */
class WalletAppliesTo
{
    /**
     * Rails: `object.allowed_fee_types` (the wallet column).
     *
     * @return list<string>|null
     */
    public function feeTypes(WalletModel $wallet): ?array
    {
        $feeTypes = $wallet->allowed_fee_types ?? [];

        return $feeTypes === [] ? null : array_values((array) $feeTypes);
    }

    /**
     * Rails: `object.billable_metrics` through the wallet_targets join.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\BillableMetric>
     */
    public function billableMetrics(WalletModel $wallet): \Illuminate\Database\Eloquent\Collection
    {
        return $wallet->billableMetrics()->get();
    }
}
