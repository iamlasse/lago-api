<?php

declare(strict_types=1);

namespace App\Services\Events\Stores\Postgres;

use Illuminate\Database\Eloquent\Builder;
use App\Services\Events\Stores\PostgresStore;

/**
 * Port of Rails' Events::Stores::Postgres::UniqueCountQuery
 * (app/services/events/stores/postgres/unique_count_query.rb).
 *
 * The events CTE keeps its query-builder bindings (see `bindings()`);
 * Rails' named binds (from_datetime / to_datetime / timezone) are inlined
 * as sanitized literals so the composed SQL stays positional. Each sql*
 * method composes the events subquery ONCE — build the SQL first, then
 * take `bindings()`.
 */
final class UniqueCountQuery
{
    private ?Builder $eventsSubquery = null;

    public function __construct(private readonly PostgresStore $store) {}

    /**
     * The bindings of the events subquery, in composition order — the
     * caller must pass them to DB::select together with the sql* output.
     *
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return $this->eventsSubquery()->getBindings();
    }

    /** Port of `query`. */
    public function sql(): string
    {
        return <<<SQL
            {$this->eventsCteSql()},

            event_values AS (
              SELECT
                property,
                SUM(adjusted_value) AS sum_adjusted_value
              FROM (
                SELECT
                  timestamp,
                  property,
                  operation_type,
                  {$this->operationValueSql()} AS adjusted_value
                FROM events_data
                ORDER BY timestamp ASC
              ) adjusted_event_values
              GROUP BY property
            )

            SELECT COALESCE(SUM(sum_adjusted_value), 0) AS aggregation FROM event_values
        SQL;
    }

    /** Port of `prorated_query`. */
    public function proratedSql(mixed $fromDatetime, mixed $toDatetime, string $timezone): string
    {
        $from = $this->store->datetimeLiteral($fromDatetime);
        $to = $this->store->datetimeLiteral($toDatetime);
        $tz = $this->store->quote($timezone);

        return <<<SQL
            {$this->eventsCteSql()},
            -- ignore if for remove event there is a following add event the same day that nullifies this one
            same_day_ignored AS (
              SELECT
                property,
                operation_type,
                timestamp,
                {$this->ignoreRemoveEventsSql($tz)} AS is_ignored
              FROM events_data as e
            ),
            -- Check if the operation type is the same as previous, so it nullifies this one
            event_values AS (
              SELECT
                property,
                operation_type,
                timestamp
              FROM (
                SELECT
                  timestamp,
                  property,
                  operation_type,
                  {$this->operationValueSql()} AS adjusted_value
                FROM same_day_ignored
                WHERE is_ignored = false
                ORDER BY timestamp ASC
              ) adjusted_event_values
              WHERE adjusted_value != 0 -- adjusted_value = 0 does not impact the total
              GROUP BY property, operation_type, timestamp
            )

            SELECT COALESCE(SUM(period_ratio), 0) as aggregation
            FROM (
              SELECT ({$this->periodRatioSql($from, $to, $tz)}) AS period_ratio
              FROM event_values
            ) cumulated_ratios
        SQL;
    }

    /** Port of `grouped_query`. */
    public function groupedSql(): string
    {
        $names = $this->groupNames();

        return <<<SQL
            {$this->eventsCteSql()},

            event_values AS (
              SELECT
                {$names},
                property,
                SUM(adjusted_value) AS sum_adjusted_value
              FROM (
                SELECT
                  timestamp,
                  property,
                  operation_type,
                  {$names},
                  {$this->groupedOperationValueSql()} AS adjusted_value
                FROM events_data
                ORDER BY timestamp ASC
              ) adjusted_event_values
              GROUP BY {$names}, property
            )

            SELECT
              {$names},
              COALESCE(SUM(sum_adjusted_value), 0) as aggregation
            FROM event_values
            GROUP BY {$names}
        SQL;
    }

    /** Port of `grouped_prorated_query`. */
    public function groupedProratedSql(mixed $fromDatetime, mixed $toDatetime, string $timezone): string
    {
        $names = $this->groupNames();
        $from = $this->store->datetimeLiteral($fromDatetime);
        $to = $this->store->datetimeLiteral($toDatetime);
        $tz = $this->store->quote($timezone);

        return <<<SQL
            {$this->eventsCteSql()},
            -- ignore if for remove event there is a following add event the same day (for grouped events) that nullifies this one
            same_day_ignored AS (
              SELECT
                {$names},
                property,
                operation_type,
                timestamp,
                {$this->ignoreRemoveGroupedEventsSql($tz)} AS is_ignored
              FROM (
                SELECT
                  {$names},
                  property,
                  operation_type,
                  timestamp
                FROM events_data
                ORDER BY timestamp ASC
              ) as e
            ),
            -- Check if the operation type is the same as previous, so it nullifies this one
            event_values AS (
              SELECT
                {$names},
                property,
                operation_type,
                timestamp
              FROM (
                SELECT
                  timestamp,
                  property,
                  operation_type,
                  {$names},
                  {$this->groupedOperationValueSql()} AS adjusted_value
                FROM same_day_ignored
                WHERE is_ignored = false
                ORDER BY timestamp ASC
              ) adjusted_event_values
              WHERE adjusted_value != 0 -- adjusted_value = 0 does not impact the total
              GROUP BY {$names}, property, operation_type, timestamp
              ORDER BY timestamp ASC
            )

            SELECT
              {$names},
              COALESCE(SUM(period_ratio), 0) as aggregation
            FROM (
              SELECT
                ({$this->groupedPeriodRatioSql($from, $to, $tz)}) AS period_ratio,
                {$names}
              FROM event_values
            ) cumulated_ratios
            GROUP BY {$names}
        SQL;
    }

    /**
     * Port of `breakdown_query` — debug helper (timestamp, property,
     * operation type, operation value per event).
     */
    public function breakdownSql(): string
    {
        return <<<SQL
            {$this->eventsCteSql()}

            SELECT
              timestamp,
              property,
              operation_type,
              {$this->operationValueSql()}
            FROM events_data
            ORDER BY timestamp ASC
        SQL;
    }

    // -- SQL fragments (Rails: private) ----------------------------------------

    /** Port of `events_cte_sql`. */
    private function eventsCteSql(): string
    {
        return 'WITH events_data AS ('.$this->eventsSubquery()->toSql().')';
    }

    /**
     * The ordered events scope carrying the aggregation's property — and,
     * in the grouped variants, the aliased group columns (g_0..gN) the CTE
     * references.
     */
    private function eventsSubquery(): Builder
    {
        if ($this->eventsSubquery === null) {
            $groups = [];
            foreach (array_values($this->store->groupedBy()) as $index => $group) {
                $groups[] = sprintf('%s AS g_%d', $this->store->propertyName($group), $index);
            }

            $this->eventsSubquery = $this->store->events(ordered: true)->selectRaw(implode(', ', [
                ...$groups,
                'timestamp',
                $this->store->propertyName().' AS property',
                $this->store->operationTypeSql().' AS operation_type',
            ]));
        }

        return $this->eventsSubquery;
    }

    /**
     * Port of `operation_value_sql` — 1 for relevant addition, -1 for
     * relevant removal; repeats of the same operation are 0.
     */
    private function operationValueSql(): string
    {
        return <<<'SQL'
            CASE
            WHEN LAG(operation_type, 1, 'remove') OVER (PARTITION BY property ORDER BY timestamp) = operation_type
            THEN 0 -- NOTE: if the first ever operation is a remove, it's not relevant; note that it's "remove" if not found, so we ignore "empty" remove
            ELSE CASE WHEN operation_type = 'add' THEN 1 ELSE -1 END
            END
        SQL;
    }

    private function groupedOperationValueSql(): string
    {
        $names = $this->groupNames();

        return <<<SQL
            CASE
            WHEN LAG(operation_type, 1, 'remove') OVER (PARTITION BY {$names}, property ORDER BY timestamp) = operation_type
            THEN 0
            ELSE CASE WHEN operation_type = 'add' THEN 1 ELSE -1 END
            END
        SQL;
    }

    private function periodRatioSql(string $from, string $to, string $tz): string
    {
        $duration = $this->store->chargesDuration() ?? 1;

        return <<<SQL
            CASE WHEN operation_type = 'add'
            THEN
              -- NOTE: duration in days between current event and next one - using end of period as final boundaries
              (
                (
                  DATE((
                    CASE WHEN (LEAD(timestamp, 1, {$to}) OVER (PARTITION BY property ORDER BY timestamp)) < {$from}
                    THEN {$from}
                    ELSE LEAD(timestamp, 1, {$to}) OVER (PARTITION BY property ORDER BY timestamp) + interval '1' day
                    END
                  )::timestamptz AT TIME ZONE {$tz})
                  - DATE((
                    CASE WHEN timestamp < {$from} THEN {$from} ELSE timestamp END
                  )::timestamptz AT TIME ZONE {$tz})
                )::numeric
              )
              /
              -- NOTE: full duration of the period
              {$duration}::numeric
            ELSE
              0 -- NOTE: duration was null so usage is null
            END
        SQL;
    }

    private function groupedPeriodRatioSql(string $from, string $to, string $tz): string
    {
        $duration = $this->store->chargesDuration() ?? 1;
        $names = $this->groupNames();

        return <<<SQL
            CASE WHEN operation_type = 'add'
            THEN
              (
                (
                  DATE((
                    CASE WHEN (LEAD(timestamp, 1, {$to}) OVER (PARTITION BY {$names}, property ORDER BY timestamp)) < {$from}
                    THEN {$from}
                    ELSE LEAD(timestamp, 1, {$to}) OVER (PARTITION BY {$names}, property ORDER BY timestamp)
                    END
                  )::timestamptz AT TIME ZONE {$tz})
                  - DATE((
                    CASE WHEN timestamp < {$from} THEN {$from} ELSE timestamp END
                  )::timestamptz AT TIME ZONE {$tz})
                )::numeric
                + 1
              )
              /
              {$duration}::numeric
            ELSE
              0
            END
        SQL;
    }

    private function ignoreRemoveEventsSql(string $tz): string
    {
        return <<<SQL
            CASE
              -- we do not ignore ADDs, if they are duplicated they'll be cleaned by adjusted value calculation
              WHEN operation_type = 'add' THEN false
              -- if there is a next event the same day is the opposite operation type, this should be ignored
              WHEN {$this->existingEventOppositeOperationTypeSql($tz)} THEN true
              ELSE false
            END
        SQL;
    }

    private function ignoreRemoveGroupedEventsSql(string $tz): string
    {
        return <<<SQL
            CASE
              WHEN operation_type = 'add' THEN false
              -- if the next event the same day is the opposite operation type, it should be ignored
              WHEN {$this->existingGroupedEventOppositeOperationTypeSql($tz)} THEN true
              ELSE false
            END
        SQL;
    }

    private function existingEventOppositeOperationTypeSql(string $tz): string
    {
        return <<<SQL
            (
              SELECT
                1
              FROM events_data next_event
              WHERE next_event.property = e.property
                AND DATE((next_event.timestamp)::timestamptz AT TIME ZONE {$tz}) = DATE((e.timestamp)::timestamptz AT TIME ZONE {$tz})
                AND next_event.operation_type <> e.operation_type
                AND next_event.timestamp > e.timestamp
              LIMIT 1
            ) = 1
        SQL;
    }

    private function existingGroupedEventOppositeOperationTypeSql(string $tz): string
    {
        $equalities = implode(' AND ', array_map(
            fn (string $name) => "next_event.{$name} = e.{$name}",
            $this->groupNamesList(),
        ));

        return <<<SQL
            (
              SELECT
                1
              FROM events_data next_event
              WHERE next_event.property = e.property
                AND {$equalities}
                AND DATE((next_event.timestamp)::timestamptz AT TIME ZONE {$tz}) = DATE((e.timestamp)::timestamptz AT TIME ZONE {$tz})
                AND next_event.operation_type <> e.operation_type
                AND next_event.timestamp > e.timestamp
              LIMIT 1
            ) = 1
        SQL;
    }

    /** @return list<string> */
    private function groupNamesList(): array
    {
        return array_map(fn (int $index) => 'g_'.$index, array_keys(array_values($this->store->groupedBy())));
    }

    private function groupNames(): string
    {
        return implode(', ', $this->groupNamesList());
    }
}
