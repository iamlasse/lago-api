<?php

declare(strict_types=1);

namespace App\Models\Events;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * Frozen-schema model for the `last_hour_events_mv` Postgres materialized
 * view.
 *
 * Port of Rails' Events::LastHourMv (app/models/events/last_hour_mv.rb).
 * The view joins the last hour of events against billable metrics and
 * their filters; it is refreshed by Clock\EventsValidationJob
 * (`REFRESH MATERIALIZED VIEW` — Rails' Scenic refresh), then read by
 * Events::PostValidationService to detect invalid events.
 *
 * The view is not writable — Rails marks the model `readonly?`; the port
 * never writes through this model (the view is refreshed with raw SQL by
 * the clock job) and reads go through the standard builder.
 */
#[Table(name: 'last_hour_events_mv')]
class LastHourMv extends BaseModel
{
    /**
     * Rails: `Events::LastHourMv.pluck("DISTINCT(organization_id)")` — the
     * organizations that had events in the last hour.
     *
     * @return list<string>
     */
    public static function distinctOrganizationIds(): array
    {
        return array_map(
            fn ($row): string => (string) $row->organization_id,
            \Illuminate\Support\Facades\DB::select(
                'SELECT DISTINCT(organization_id) FROM last_hour_events_mv'
            )
        );
    }

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'field_name_mandatory' => 'boolean',
            'numeric_field_mandatory' => 'boolean',
            'is_numeric_field_value' => 'boolean',
            'has_filter_keys' => 'boolean',
            'has_valid_filter_values' => 'boolean',
        ];
    }
}
