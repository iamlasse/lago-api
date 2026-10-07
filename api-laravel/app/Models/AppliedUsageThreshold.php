<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ConnectionResolvable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' AppliedUsageThreshold (app/models/applied_usage_threshold.rb)
 * — the join row recording that a progressive-billing invoice was created
 * because a usage threshold was passed.
 *
 * Rails monetizes `passed_threshold_amount_cents` as a COMPUTED attribute (no
 * column, disable_validation): the period-cumulative amount actually billed
 * for a recurring threshold, the threshold amount for a fixed one.
 */
#[Table(name: 'applied_usage_thresholds')]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'usage_threshold_id',
    'invoice_id',
    'lifetime_usage_amount_cents',
])]
class AppliedUsageThreshold extends BaseModel
{
    use ConnectionResolvable;
    use HasFactory;

    protected $attributes = [
        'lifetime_usage_amount_cents' => 0,
    ];

    /** Rails: belongs_to :usage_threshold, -> { with_discarded }. */
    public function usageThreshold(): BelongsTo
    {
        return $this->belongsTo(UsageThreshold::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Rails: #passed_threshold_amount_cents — the recurring threshold is
     * billed by whole multiples of itself, so the passed amount is the largest
     * multiple of the threshold below the lifetime usage total.
     */
    public function passedThresholdAmountCents(): int
    {
        $threshold = $this->usageThreshold;

        if ($threshold !== null && $threshold->recurring) {
            $amount = (int) $threshold->amount_cents;

            if ($amount === 0) {
                return 0;
            }

            return (int) $this->lifetime_usage_amount_cents - ($this->lifetime_usage_amount_cents % $amount);
        }

        return (int) ($threshold?->amount_cents ?? 0);
    }

    protected function casts(): array
    {
        return [
            'lifetime_usage_amount_cents' => 'int',
        ];
    }
}
