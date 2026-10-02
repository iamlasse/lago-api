<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Frozen-schema model for `adjusted_fees` — Rails' AdjustedFee
 * (app/models/adjusted_fee.rb): per-fee overrides (units / amount / display
 * name) applied on draft invoices at billing time.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
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
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'adjusted_fees')]
class AdjustedFee extends BaseModel
{
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
}
