<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' CouponTarget (app/models/coupon_target.rb) — the
 * coupon_targets join between a coupon and the plans / billable metrics it
 * is limited to (coupons.limited_plans / limited_billable_metrics).
 *
 * The frozen schema also carries catalog_plan_id (Rails' CatalogPlan);
 * catalog plans are not part of the legacy-engine port, so only plan_id /
 * billable_metric_id targets are ever written here. The schema CHECK
 * constraint (num_nonnulls(plan_id, catalog_plan_id) <= 1) already guards
 * what Rails' ParentPresenceValidator (single_plan_target) enforces.
 */
#[Fillable([
    'coupon_id',
    'plan_id',
    'billable_metric_id',
    'organization_id',
    'deleted_at',
])]
#[Table(name: 'coupon_targets')]
class CouponTarget extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** Rails: `belongs_to :plan, optional: true`. */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Rails: `belongs_to :billable_metric, optional: true`. */
    public function billableMetric(): BelongsTo
    {
        return $this->belongsTo(BillableMetric::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
