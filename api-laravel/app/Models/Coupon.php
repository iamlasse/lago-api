<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponType;
use App\Enums\CouponStatus;
use App\Enums\CouponFrequency;
use App\Enums\CouponExpiration;
use App\Models\Casts\BcNumeric;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Coupon (app/models/coupon.rb) for the invoicing pipeline.
 * TODO(port): coupon_targets / plan overrides — Rails links limited plans
 * and billable metrics through the `coupon_targets` table, which is not
 * modeled yet; the pipeline only reads `limited_plans` /
 * `limited_billable_metrics` flags and the target ids.
 */
#[Fillable([
    'organization_id',
    'name',
    'code',
    'status',
    'terminated_at',
    'amount_cents',
    'amount_currency',
    'expiration',
    'coupon_type',
    'percentage_rate',
    'frequency',
    'frequency_duration',
    'expiration_at',
    'reusable',
    'limited_plans',
    'limited_billable_metrics',
    'description',
])]
#[Table(name: 'coupons')]
class Coupon extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    public function appliedCoupons(): HasMany
    {
        return $this->hasMany(AppliedCoupon::class);
    }

    public function typeEnum(): ?CouponType
    {
        return $this->coupon_type instanceof CouponType ? $this->coupon_type : ($this->coupon_type === null ? null : CouponType::tryFrom((int) $this->coupon_type));
    }

    public function frequencyEnum(): ?CouponFrequency
    {
        return $this->frequency instanceof CouponFrequency ? $this->frequency : ($this->frequency === null ? null : CouponFrequency::tryFrom((int) $this->frequency));
    }

    /** Rails: `percentage?` — coupon_type == :percentage. */
    public function percentage(): bool
    {
        return $this->typeEnum() === CouponType::Percentage;
    }

    /** Rails: `forever?` on the frequency. */
    public function forever(): bool
    {
        return $this->frequencyEnum() === CouponFrequency::Forever;
    }

    /**
     * Port of `parent_and_overriden_plans` — the coupon's limited plans plus
     * their children (overrides). TODO(port): coupon_targets do not exist
     * yet, so the limited plan ids cannot be resolved through the join
     * table; the pipeline treats limited coupons as unlimited until the
     * targets table is ported.
     */
    public function parentAndOverridenPlans()
    {
        return collect();
    }

    protected function casts(): array
    {
        return [
            'status' => CouponStatus::class,
            'terminated_at' => 'datetime',
            'amount_cents' => 'integer',
            'expiration' => CouponExpiration::class,
            'coupon_type' => CouponType::class,
            'percentage_rate' => [BcNumeric::class, 'scale' => 5],
            'frequency' => CouponFrequency::class,
            'frequency_duration' => 'integer',
            'expiration_at' => 'datetime',
            'reusable' => 'boolean',
            'limited_plans' => 'boolean',
            'limited_billable_metrics' => 'boolean',
        ];
    }
}
