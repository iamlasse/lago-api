<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ConnectionResolvable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' LifetimeUsage (app/models/lifetime_usage.rb) — the
 * cumulative usage ledger of a subscription across its upgrades/downgrades:
 * invoiced usage (finalized + draft invoices), current (unbilled) usage and
 * historical (externally reported) usage.
 */
#[Table(name: 'lifetime_usages')]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'subscription_id',
    'current_usage_amount_cents',
    'invoiced_usage_amount_cents',
    'historical_usage_amount_cents',
    'recalculate_current_usage',
    'recalculate_invoiced_usage',
    'current_usage_amount_refreshed_at',
    'invoiced_usage_amount_refreshed_at',
])]
class LifetimeUsage extends BaseModel
{
    use ConnectionResolvable;
    use HasFactory;
    use SoftDeletes;

    protected $attributes = [
        'current_usage_amount_cents' => 0,
        'invoiced_usage_amount_cents' => 0,
        'historical_usage_amount_cents' => 0,
        'recalculate_current_usage' => false,
        'recalculate_invoiced_usage' => false,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Rails: #total_amount_cents. */
    public function totalAmountCents(): int
    {
        return (int) $this->historical_usage_amount_cents
            + (int) $this->invoiced_usage_amount_cents
            + (int) $this->current_usage_amount_cents;
    }

    /**
     * Rails' monetize currency resolver — the subscription plan's currency.
     */
    public function currency(): ?string
    {
        return $this->subscription?->plan?->amount_currency;
    }

    protected function casts(): array
    {
        return [
            'current_usage_amount_cents' => 'int',
            'invoiced_usage_amount_cents' => 'int',
            'historical_usage_amount_cents' => 'int',
            'recalculate_current_usage' => 'boolean',
            'recalculate_invoiced_usage' => 'boolean',
            'current_usage_amount_refreshed_at' => 'datetime',
            'invoiced_usage_amount_refreshed_at' => 'datetime',
        ];
    }
}
