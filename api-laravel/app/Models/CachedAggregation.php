<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use App\Models\Casts\BcNumeric;

/**
 * Frozen-schema model for `cached_aggregations` — Rails'
 * CachedAggregation (app/models/cached_aggregation.rb). Stores the carried
 * over aggregation value of recurring metrics between billing periods.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'timestamp',
    'external_subscription_id',
    'charge_id',
    'group_id',
    'current_aggregation',
    'max_aggregation',
    'max_aggregation_with_proration',
    'grouped_by',
    'charge_filter_id',
    'current_amount',
    'event_transaction_id',
    'presentation_breakdowns',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'cached_aggregations')]
class CachedAggregation extends BaseModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'current_aggregation' => [BcNumeric::class, 'scale' => 15],
            'max_aggregation' => [BcNumeric::class, 'scale' => 15],
            'max_aggregation_with_proration' => [BcNumeric::class, 'scale' => 15],
            'grouped_by' => 'array',
            'current_amount' => [BcNumeric::class, 'scale' => 15],
            'presentation_breakdowns' => 'array',
        ];
    }
}
