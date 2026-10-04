<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * TODO(port): clickhouse store — the 954-line Events::Stores::ClickHouseStore
 * (aggregations over ClickHouse::EventsRaw/EventsEnriched). Selected by
 * StoreFactory only when LAGO_CLICKHOUSE_ENABLED is set and the organization
 * opted into the clickhouse events store; neither is the case in this
 * environment, so PostgresStore is the active path. Until the ClickHouse
 * client lands, every aggregation entry point throws (BaseStore's
 * NotImplementedError equivalents).
 *
 * Rails' ClickHouseStore entry points to port:
 * events, distinct_codes_and_property_combinations, events_values,
 * last_event, prorated_events_values, count, grouped_count, max,
 * grouped_max, last, grouped_last, sum, grouped_sum,
 * sum_precise_total_amount_cents, grouped_sum_precise_total_amount_cents,
 * prorated_sum, grouped_prorated_sum, sum_date_breakdown, weighted_sum,
 * grouped_weighted_sum, unique_count, prorated_unique_count,
 * grouped_unique_count, grouped_prorated_unique_count (+ its dedup /
 * enriched-views SQL in Stores::Utils::ClickHouseSqlHelpers).
 */
class ClickHouseStore extends BaseStore
{
    //
}
