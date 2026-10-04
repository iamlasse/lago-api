<?php

declare(strict_types=1);

namespace App\Services\Events\Stores\ClickHouse;

use App\Services\Events\Stores\ClickHouseStore;

/**
 * Port of Rails' Events::Stores::Clickhouse::UniqueCountQuery
 * (app/services/events/stores/clickhouse/unique_count_query.rb) — the
 * add/remove aware unique count over the deduplicated `events` CTE.
 *
 * Unlike the Postgres implementation the ClickHouse one never ignores Add
 * events (the adjusted value handles them); Remove events are only ignored
 * when they are NOT the last event of the day (a join on != is not
 * supported by the production ClickHouse version, so a window function is
 * used instead — see the Rails source note).
 *
 * The :named placeholders (:from_datetime, :to_datetime,
 * :decimal_date_scale, :timezone) are bound by the store via bindParams,
 * exactly like Rails' sanitize_colon + sanitize_sql_for_conditions pair.
 */
class UniqueCountQuery
{
    public function __construct(protected ClickHouseStore $store) {}

    public function query(): string
    {
        // NOTE: First sum calculates all operation values for a specific
        // property (for instance 2 relevant additions with 1 relevant
        // removal [0, 1, 0, -1, 1] returns 1). The next sum combines all
        // properties into a single result.
        $eventValues = <<<'SQL'
            SELECT
              property,
              SUM(adjusted_value) AS sum_adjusted_value
            FROM (
              SELECT
                timestamp,
                property,
                operation_type,
                {operation_value_sql} AS adjusted_value
              FROM events
              ORDER BY timestamp ASC, property ASC
            ) adjusted_event_values
            GROUP BY property
        SQL;

        $ctes = $this->eventsCteSql();
        $ctes['event_values'] = str_replace('{operation_value_sql}', $this->operationValueSql(), $eventValues);

        return $this->store->withCtes(
            $ctes,
            'SELECT coalesce(SUM(sum_adjusted_value), 0) AS aggregation FROM event_values',
        );
    }

    public function proratedQuery(): string
    {
        $sameDayIgnored = <<<'SQL'
            SELECT
              property,
              operation_type,
              timestamp,
              {ignore_remove_events_sql} AS is_ignored
            FROM (
              SELECT
                timestamp,
                property,
                operation_type,
                -- Check if this is the last event of the day for this property
                timestamp = MAX(timestamp) OVER (PARTITION BY property, toDate(timestamp, :timezone)) AS is_last_event_of_day
              FROM events
              ORDER BY timestamp ASC, property ASC
            ) as e
        SQL;

        $eventValues = <<<'SQL'
            SELECT
              property,
              operation_type,
              timestamp
            FROM (
              SELECT
                timestamp,
                property,
                operation_type,
                {operation_value_sql} AS adjusted_value
              FROM same_day_ignored
              WHERE is_ignored = false
              ORDER BY timestamp ASC, property ASC
            ) adjusted_event_values
            WHERE adjusted_value != 0 -- adjusted_value = 0 does not impact the total
            GROUP BY property, operation_type, timestamp
        SQL;

        $ctes = $this->eventsCteSql();
        $ctes['same_day_ignored'] = str_replace(
            '{ignore_remove_events_sql}',
            $this->ignoreRemoveEventsSql(),
            $sameDayIgnored,
        );
        $ctes['event_values'] = str_replace(
            '{operation_value_sql}',
            $this->operationValueSql(),
            $eventValues,
        );

        return $this->store->withCtes(
            $ctes,
            'SELECT coalesce(SUM(period_ratio), 0) as aggregation FROM ('
                .'SELECT ('.$this->periodRatioSql().') AS period_ratio FROM event_values'
                .') cumulated_ratios',
        );
    }

    public function groupedQuery(): string
    {
        $joinedGroupNames = $this->joinedGroupNames();

        $eventValues = <<<'SQL'
            SELECT
              {group_names},
              property,
              SUM(adjusted_value) AS sum_adjusted_value
            FROM (
              SELECT
                timestamp,
                property,
                operation_type,
                {group_names},
                {grouped_operation_value_sql} AS adjusted_value
              FROM events
              ORDER BY timestamp ASC, property ASC
            ) adjusted_event_values
            GROUP BY {group_names}, property
        SQL;

        $eventValues = str_replace(
            ['{group_names}', '{grouped_operation_value_sql}'],
            [$joinedGroupNames, $this->groupedOperationValueSql()],
            $eventValues,
        );

        $ctes = $this->groupedEventsCteSql();
        $ctes['event_values'] = $eventValues;

        return $this->store->withCtes(
            $ctes,
            'SELECT '.$joinedGroupNames.', coalesce(SUM(sum_adjusted_value), 0) as aggregation'
                .' FROM event_values GROUP BY '.$joinedGroupNames,
        );
    }

    public function groupedProratedQuery(): string
    {
        $joinedGroupNames = $this->joinedGroupNames();

        // Only ignore remove events if they are NOT the last event of the day.
        $sameDayIgnored = <<<'SQL'
            SELECT
              {group_names},
              property,
              operation_type,
              timestamp,
              {ignore_remove_events_sql} AS is_ignored
            FROM (
              SELECT
                timestamp,
                property,
                operation_type,
                {group_names},
                -- Check if this is the last event of the day for this property and group
                timestamp = MAX(timestamp) OVER (PARTITION BY {group_names}, property, toDate(timestamp, :timezone)) AS is_last_event_of_day
              FROM events
              ORDER BY timestamp ASC, property ASC
            ) as e
        SQL;

        $eventValues = <<<'SQL'
            SELECT
              {group_names},
              property,
              operation_type,
              timestamp
            FROM (
              SELECT
                timestamp,
                property,
                operation_type,
                {group_names},
                {grouped_operation_value_sql} AS adjusted_value
              FROM same_day_ignored
              WHERE is_ignored = false
              ORDER BY timestamp ASC, property ASC
            ) adjusted_event_values
            WHERE adjusted_value != 0 -- adjusted_value = 0 does not impact the total
            GROUP BY {group_names}, property, operation_type, timestamp
        SQL;

        $ctes = $this->groupedEventsCteSql();
        $ctes['same_day_ignored'] = str_replace(
            ['{group_names}', '{ignore_remove_events_sql}'],
            [$joinedGroupNames, $this->ignoreRemoveEventsSql()],
            $sameDayIgnored,
        );
        $ctes['event_values'] = str_replace(
            ['{group_names}', '{grouped_operation_value_sql}'],
            [$joinedGroupNames, $this->groupedOperationValueSql()],
            $eventValues,
        );

        return $this->store->withCtes(
            $ctes,
            'SELECT '.$joinedGroupNames.', coalesce(SUM(period_ratio), 0) as aggregation FROM ('
                .'SELECT ('.$this->groupedPeriodRatioSql().') AS period_ratio, '.$joinedGroupNames
                .' FROM event_values) cumulated_ratios GROUP BY '.$joinedGroupNames,
        );
    }

    // -- CTE fragments ---------------------------------------------------------

    /**
     * The common `events` CTE: timestamp, property (the aggregation value)
     * and the operation type, coalesced to 'add'.
     *
     * @return array<string, string>
     */
    private function eventsCteSql(): array
    {
        return $this->store->eventsCteQueries(
            forceFrom: false,
            ordered: true,
            select: [
                'events_enriched.timestamp AS timestamp',
                'events_enriched.value AS property',
                'coalesce(nullif('.$this->store->operationTypeSql().", ''), 'add') AS operation_type",
            ],
            deduplicatedColumns: ['value', 'sorted_properties'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function groupedEventsCteSql(): array
    {
        [$groups] = $this->store->groupedColumns();

        return $this->store->eventsCteQueries(
            forceFrom: false,
            ordered: true,
            select: [
                ...$groups,
                'events_enriched.timestamp AS timestamp',
                'events_enriched.value AS property',
                'coalesce(nullif('.$this->store->operationTypeSql().", ''), 'add') AS operation_type",
            ],
            deduplicatedColumns: ['value', 'sorted_properties'],
        );
    }

    /**
     * Returns 1 for a relevant addition, -1 for a relevant removal.
     * If the property is already added, another addition returns 0;
     * it returns 1 otherwise. If the property is already removed or not yet
     * present, another removal returns 0; it returns -1 otherwise.
     */
    private function operationValueSql(): string
    {
        return <<<'SQL'
            if (
              operation_type = 'add',
              (if(
                (lagInFrame(operation_type, 1) OVER (PARTITION BY property ORDER BY timestamp ROWS BETWEEN 1 PRECEDING AND 1 PRECEDING)) = 'add',
                toDecimal32(0, 0),
                toDecimal32(1, 0)
              )),
              (if(
                (lagInFrame(operation_type, 1, 'remove') OVER (PARTITION BY property ORDER BY timestamp ROWS BETWEEN 1 PRECEDING AND 1 PRECEDING)) = 'remove',
                toDecimal32(0, 0),
                toDecimal32(-1, 0)
              ))
            )
        SQL;
    }

    private function groupedOperationValueSql(): string
    {
        $groups = $this->joinedGroupNames();

        return <<<SQL
            if (
              operation_type = 'add',
              (if(
                (lagInFrame(operation_type, 1) OVER (PARTITION BY {$groups}, property ORDER BY timestamp ASC ROWS BETWEEN 1 PRECEDING AND 1 PRECEDING)) = 'add',
                toDecimal32(0, 0),
                toDecimal32(1, 0)
              ))
              ,
              (if(
                (lagInFrame(operation_type, 1, 'remove') OVER (PARTITION BY {$groups}, property ORDER BY timestamp ASC ROWS BETWEEN 1 PRECEDING AND 1 PRECEDING)) = 'remove',
                toDecimal32(0, 0),
                toDecimal32(-1, 0)
              ))
            )
        SQL;
    }

    /**
     * The inclusive day count in the customer timezone over the charges
     * duration, same as PG. Remove events contribute 0.
     */
    private function periodRatioSql(): string
    {
        $duration = $this->store->chargesDuration() ?? 1;

        return <<<SQL
            if(
              operation_type = 'add',
              (
                toDecimal64(
                  -- inclusive day count in customer TZ, same as PG
                  date_diff(
                    'days',
                    toDate(
                      toTimezone(
                        if(
                          timestamp < toDateTime64(:from_datetime, 3, 'UTC'),
                          toDateTime64(:from_datetime, 3, 'UTC'),
                          timestamp
                        ),
                        :timezone
                      )
                    ),
                    toDate(
                      toTimezone(
                        if(
                          -- if next event is before the period start, clamp to :from_datetime (no +1 day),
                          -- else add 1 day to make the range inclusive, just like PG does.
                          (leadInFrame(timestamp, 1, toDateTime64(:to_datetime, 3, 'UTC'))
                             OVER (PARTITION BY property ORDER BY timestamp ASC
                                   ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)
                          ) < toDateTime64(:from_datetime, 3, 'UTC'),
                          toDateTime64(:from_datetime, 3, 'UTC'),
                          addDays(
                            (leadInFrame(timestamp, 1, toDateTime64(:to_datetime, 3, 'UTC'))
                               OVER (PARTITION BY property ORDER BY timestamp ASC
                                     ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)
                            ),
                            1
                          )
                        ),
                        :timezone
                      )
                    )
                  ),
                  :decimal_date_scale)
                / {$duration}
              ),
              0
            )
        SQL;
    }

    private function groupedPeriodRatioSql(): string
    {
        $groups = $this->joinedGroupNames();
        $duration = $this->store->chargesDuration() ?? 1;

        return <<<SQL
            if(
              operation_type = 'add',
              (
                toDecimal64(
                  date_diff(
                    'days',
                    toDate(
                      toTimezone(
                        if(
                          timestamp < toDateTime64(:from_datetime, 3, 'UTC'),
                          toDateTime64(:from_datetime, 3, 'UTC'),
                          timestamp
                        ),
                        :timezone
                      )
                    ),
                    toDate(
                      toTimezone(
                        if(
                          (leadInFrame(timestamp, 1, toDateTime64(:to_datetime, 3, 'UTC'))
                             OVER (PARTITION BY {$groups}, property ORDER BY timestamp ASC
                                   ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)
                          ) < toDateTime64(:from_datetime, 3, 'UTC'),
                          toDateTime64(:from_datetime, 3, 'UTC'),
                          addDays(
                            (leadInFrame(timestamp, 1, toDateTime64(:to_datetime, 3, 'UTC'))
                               OVER (PARTITION BY {$groups}, property ORDER BY timestamp ASC
                                     ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)
                            ),
                            1
                          )
                        ),
                        :timezone
                      )
                    )
                  ),
                  :decimal_date_scale)
                / {$duration}
              ),
              0
            )
        SQL;
    }

    /**
     * Only NOT ignore remove events if they are the last event of the day.
     */
    private function ignoreRemoveEventsSql(): string
    {
        return <<<'SQL'
            CASE
              -- Never ignore add events
              WHEN operation_type = 'add' THEN false
              -- Only ignore remove events if they are NOT the last event of the day
              WHEN operation_type = 'remove' AND NOT is_last_event_of_day THEN true
              ELSE false
            END
        SQL;
    }

    private function joinedGroupNames(): string
    {
        [, $names] = $this->store->groupedColumns();

        return $names;
    }
}
