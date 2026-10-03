<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `wallet_targets` (Rails' WalletTarget): a
 * wallet's billable-metric limitation — the wallet may only pay fees
 * produced by the targeted metrics.
 */
#[Table(name: 'wallet_targets')]
class WalletTarget extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'wallet_id',
        'billable_metric_id',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function billableMetric(): BelongsTo
    {
        return $this->belongsTo(BillableMetric::class);
    }

    /** Rails: `belongs_to :organization` via Wallet::Target (unused in services). */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
