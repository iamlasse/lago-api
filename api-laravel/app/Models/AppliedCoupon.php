<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponFrequency;
use App\Models\Casts\BcNumeric;
use App\Enums\AppliedCouponStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' AppliedCoupon (app/models/applied_coupon.rb).
 */
#[Fillable([
    'coupon_id',
    'customer_id',
    'status',
    'amount_cents',
    'amount_currency',
    'terminated_at',
    'percentage_rate',
    'frequency',
    'frequency_duration',
    'frequency_duration_remaining',
    'organization_id',
])]
#[Table(name: 'applied_coupons')]
class AppliedCoupon extends BaseModel
{
    use HasFactory;

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function statusEnum(): ?AppliedCouponStatus
    {
        return $this->status instanceof AppliedCouponStatus ? $this->status : ($this->status === null ? null : AppliedCouponStatus::tryFrom((int) $this->status));
    }

    public function frequencyEnum(): ?CouponFrequency
    {
        return $this->frequency instanceof CouponFrequency ? $this->frequency : ($this->frequency === null ? null : CouponFrequency::tryFrom((int) $this->frequency));
    }

    public function isActive(): bool
    {
        return $this->statusEnum() === AppliedCouponStatus::Active;
    }

    /** Rails: `once?`. */
    public function once(): bool
    {
        return $this->frequencyEnum() === CouponFrequency::Once;
    }

    /** Rails: `recurring?`. */
    public function recurring(): bool
    {
        return $this->frequencyEnum() === CouponFrequency::Recurring;
    }

    /** Rails: `forever?`. */
    public function forever(): bool
    {
        return $this->frequencyEnum() === CouponFrequency::Forever;
    }

    public function percentage(): bool
    {
        return $this->coupon->percentage();
    }

    /**
     * Port of `remaining_amount` — for `once` fixed-amount coupons the
     * amount not yet consumed by previous invoices (credits whose invoice
     * is not voided/closed/deleted).
     */
    public function remainingAmount(): int
    {
        $alreadyApplied = (int) Credit::query()
            ->join('invoices', 'invoices.id', '=', 'credits.invoice_id')
            ->where('credits.applied_coupon_id', $this->id)
            ->whereNotIn('invoices.status', [
                \App\Enums\InvoiceStatus::Voided->value,
                \App\Enums\InvoiceStatus::Closed->value,
                \App\Enums\InvoiceStatus::Deleted->value,
            ])
            ->sum('credits.amount_cents');

        return (int) $this->amount_cents - $alreadyApplied;
    }

    /** Port of `mark_as_terminated!`. */
    public function markAsTerminated(): void
    {
        $this->status = AppliedCouponStatus::Terminated;
        $this->terminated_at = now();
        $this->save();
    }

    /** Port of `scope :active`. */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('applied_coupons.status', AppliedCouponStatus::Active->value);
    }

    protected function casts(): array
    {
        return [
            'status' => AppliedCouponStatus::class,
            'amount_cents' => 'integer',
            'terminated_at' => 'datetime',
            'percentage_rate' => [BcNumeric::class, 'scale' => 5],
            'frequency' => CouponFrequency::class,
            'frequency_duration' => 'integer',
            'frequency_duration_remaining' => 'integer',
        ];
    }
}
