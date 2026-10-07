<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeeType;
use App\Models\Casts\BcNumeric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `adjusted_fees` — Rails' AdjustedFee
 * (app/models/adjusted_fee.rb): per-fee overrides (units / amount / display
 * name) applied on draft invoices at billing time.
 */
#[Fillable([
    'fee_id',
    'invoice_id',
    'subscription_id',
    'charge_id',
    'invoice_display_name',
    'fee_type',
    'adjusted_units',
    'adjusted_amount',
    'units',
    'unit_amount_cents',
    'properties',
    'group_id',
    'grouped_by',
    'charge_filter_id',
    'unit_precise_amount_cents',
    'organization_id',
    'fixed_charge_id',
])]
#[Table(name: 'adjusted_fees')]
class AdjustedFee extends BaseModel
{
    use HasFactory;

    // -- Relationships (port of the Rails model's associations) ---------------

    /** Rails: `belongs_to :invoice`. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Rails: `belongs_to :subscription`. */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Rails: `belongs_to :fee, optional: true`. */
    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    /** Rails: `belongs_to :charge, optional: true`. */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /** Rails: `belongs_to :fixed_charge, optional: true`. */
    public function fixedCharge(): BelongsTo
    {
        return $this->belongsTo(FixedCharge::class);
    }

    /** Rails: `belongs_to :group, optional: true`. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** Rails: `belongs_to :charge_filter, optional: true`. */
    public function chargeFilter(): BelongsTo
    {
        return $this->belongsTo(ChargeFilter::class);
    }

    /** Rails: `belongs_to :organization`. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // -- Domain methods -------------------------------------------------------

    /** Rails: `adjusted_display_name?` — only the display name was adjusted. */
    public function adjustedDisplayName(): bool
    {
        return ! $this->adjusted_units && ! $this->adjusted_amount;
    }

    protected function casts(): array
    {
        return [
            'fee_type' => FeeType::class,
            'adjusted_units' => 'boolean',
            'adjusted_amount' => 'boolean',
            'units' => [BcNumeric::class, 'scale' => 15],
            'unit_amount_cents' => 'integer',
            'properties' => 'array',
            'grouped_by' => 'array',
            'unit_precise_amount_cents' => [BcNumeric::class, 'scale' => 15],
        ];
    }

    // -- Scopes ---------------------------------------------------------------

    /**
     * Rails: `scope :matching_charge_boundaries` — charge fees whose stored
     * properties boundaries equal the billing period being (re)calculated.
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function matchingChargeBoundaries(Builder $query, object $boundaries): Builder
    {
        $from = $boundaries->chargesFromDatetime?->format('Y-m-d\\TH:i:s.v\\Z');
        $to = $boundaries->chargesToDatetime?->format('Y-m-d\\TH:i:s.v\\Z');

        return $query
            ->where('fee_type', FeeType::Charge)
            ->where("properties->>'charges_from_datetime'", $from)
            ->where("properties->>'charges_to_datetime'", $to);
    }
}
