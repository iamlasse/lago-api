<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `payment_intents` (Rails' PaymentIntent — the
 * hosted-checkout link record; NOT Stripe's PaymentIntent object).
 *
 * `status` is an integer column — Rails' `enum :status, %i[active expired]`,
 * 0-based (never renumber). One active intent per invoice (partial unique
 * index in the schema).
 */
#[Fillable([
    'invoice_id',
    'organization_id',
    'payment_url',
    'status',
    'expires_at',
    'provider_session_id',
])]
#[Table(name: 'payment_intents')]
class PaymentIntent extends BaseModel
{
    use HasFactory;

    /** Rails: PaymentIntent::STATUSES (enum order = stored value). */
    public const STATUSES = ['active', 'expired'];

    /** Rails: attribute :expires_at, default: -> { 24.hours.from_now }. */
    protected $attributes = [
        'status' => 0,
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function statusName(): string
    {
        return self::STATUSES[(int) $this->status] ?? 'active';
    }

    public function setActive(): void
    {
        $this->status = 0;
    }

    public function setExpired(): void
    {
        $this->status = 1;
    }

    public function active(): bool
    {
        return (int) $this->status === 0;
    }

    /** Rails: scope :non_expired. */
    public function scopeNonExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /** Rails: scope :awaiting_expiration. */
    public function scopeAwaitingExpiration($query)
    {
        return $query->where('status', 0)->where('expires_at', '<=', now());
    }

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
