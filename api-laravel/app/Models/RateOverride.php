<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `rate_overrides`. Port of the Rails RateOverride
 * model (app/models/rate_override.rb) — a phase's own pricing, replacing
 * the rate card's active rate for that phase.
 */
#[Fillable([
    'organization_id',
    'rate_model',
    'rate_properties',
    'min_amount_cents',
    'billing_interval_count',
    'billing_interval_unit',
    'pricing_unit_conversion_rate',
])]
#[Table(name: 'rate_overrides')]
class RateOverride extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** NOT NULL columns with DB defaults, mirrored on new instances. */
    protected $attributes = [
        'rate_properties' => '{}',
        'min_amount_cents' => 0,
    ];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :organization`. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `belongs_to :billable_metric` (assigned by the services). */
    public function billableMetric(): BelongsTo
    {
        return $this->belongsTo(BillableMetric::class);
    }

    /** Rails: `has_one :rate_phase` (unique index on rate_phases.rate_override_id). */
    public function ratePhase()
    {
        return $this->hasOne(RatePhase::class);
    }

    /**
     * Rails: `properties` — the charge validators read pricing data from a
     * `properties` attribute.
     *
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return (array) ($this->rate_properties ?? []);
    }

    protected function casts(): array
    {
        return [
            'rate_properties' => 'array',
            'min_amount_cents' => 'integer',
            'billing_interval_count' => 'integer',
            'billing_interval_unit' => \App\Enums\RateCardRateBillingIntervalUnit::class,
            'pricing_unit_conversion_rate' => 'string',
        ];
    }
}
