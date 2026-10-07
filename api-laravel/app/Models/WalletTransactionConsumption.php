<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `wallet_transaction_consumptions` (Rails'
 * WalletTransactionConsumption): the per-transaction ledger entry tying an
 * outbound (voided / consumed) movement to the inbound grants it drew from.
 */
#[Table(name: 'wallet_transaction_consumptions')]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'inbound_wallet_transaction_id',
    'outbound_wallet_transaction_id',
    'consumed_amount_cents',
])]
class WalletTransactionConsumption extends BaseModel
{
    use HasFactory;

    public function inboundWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'inbound_wallet_transaction_id');
    }

    public function outboundWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'outbound_wallet_transaction_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
