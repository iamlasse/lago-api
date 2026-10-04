<?php

declare(strict_types=1);

namespace App\Services\Events\Stores\Postgres;

use Illuminate\Database\Eloquent\Builder;
use App\Services\Events\Stores\PostgresStore;

/**
 * Port of Rails' Events::Stores::Postgres::WeightedSumQuery
 * (app/services/events/stores/postgres/weighted_sum_query.rb).
 *
 * The events CTE keeps its query-builder bindings (see `bindings()`);
 * Rails' named binds (from_datetime / to_datetime / initial_value) are
 * inlined as sanitized literals so the composed SQL stays positional.
 * Each sql* method composes the events subquery ONCE — build the SQL
 * first, then take `bindings()`.
 */
final class WeightedSumQuery
{
    private ?Builder $eventsSubquery = null;

    public function __construct(private readonly PostgresStore $store) {}

    /**
     * The bindings of the events subquery, in composition order.
     *
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return $this->eventsSubquery()->getBindings();
    }

    /** Port of `query`. */
    public function sql(mixed $fromDatetime, mixed $toDatetime, string|int|float $initialValue): string
    {
        $from = $this->store->datetimeLiteral($fromDatetime);
        $to = $this->store->datetimeLiteral($toDatetime);
        $initial = $this->store->quote($initialValue);

        return <<<SQL
            {$this->eventsCteSql($from, $to, $initial)}

            SELECT
              SUM(period_ratio) as aggregation,
              SUM(difference) as variation_with_initial,
              COUNT(*) as rows_count
            FROM (
              SELECT ({$this->periodRatioSql($to)}) AS period_ratio, difference
              FROM events_data
            ) cumulated_ratios
        SQL;
    }

    /**
     * Port of `grouped_query(initial_values:)` — initial values must be
     * formatted ({ groups: {...}, value: ...} per group), see the store's
     * `formattedWeightedSumInitialValues`.
     *
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     */
    public function groupedSql(mixed $fromDatetime, mixed $toDatetime, array $initialValues): string
    {
        $from = $this->store->datetimeLiteral($fromDatetime);
        $to = $this->store->datetimeLiteral($toDatetime);
        $names = $this->groupNames();

        return <<<SQL
            {$this->groupedEventsCteSql($from, $to, $initialValues)}

            SELECT
              {$names},
              SUM(period_ratio) as aggregation,
              SUM(difference) as variation_with_initial,
              COUNT(*) as rows_count
            FROM (
              SELECT
                {$names},
                ({$this->groupedPeriodRatioSql($to)}) AS period_ratio,
                difference
              FROM events_data
            ) cumulated_ratios
            GROUP BY {$names}
        SQL;
    }

    /**
     * Port of `breakdown_query` — debug helper (timestamp, difference,
     * cumul, second duration, period ratio per event).
     */
    public function breakdownSql(mixed $fromDatetime, mixed $toDatetime): string
    {
        $from = $this->store->datetimeLiteral($fromDatetime);
        $to = $this->store->datetimeLiteral($toDatetime);

        return <<<SQL
            {$this->eventsCteSql($from, $to, '0')}

            SELECT
              timestamp,
              difference,
              SUM(difference) OVER (ORDER BY timestamp ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS cumul,
              EXTRACT(epoch FROM lead(timestamp, 1, {$to}) OVER (ORDER BY timestamp) - timestamp) AS second_duration,
              ({$this->periodRatioSql($to)}) AS period_ratio
            FROM events_data
            ORDER BY timestamp ASC
        SQL;
    }

    // -- SQL fragments (Rails: private) ----------------------------------------

    /** Port of `events_cte_sql`. */
    private function eventsCteSql(string $from, string $to, string $initial): string
    {
        return 'WITH events_data AS ('.implode(' UNION ALL ', [
            '('.$this->initialValueSql($from, $initial).')',
            '('.$this->eventsSubquery()->toSql().')',
            '('.$this->endOfPeriodValueSql($to).')',
        ]).')';
    }

    /** Port of `grouped_events_cte_sql`. */
    private function groupedEventsCteSql(string $from, string $to, array $initialValues): string
    {
        return 'WITH events_data AS ('.implode(' UNION ALL ', [
            '('.$this->groupedInitialValueSql($initialValues, $from).')',
            '('.$this->eventsSubquery()->toSql().')',
            '('.$this->groupedEndOfPeriodValueSql($initialValues, $to).')',
        ]).')';
    }

    /** Port of `initial_value_sql`. */
    private function initialValueSql(string $from, string $initial): string
    {
        return <<<SQL
            SELECT *
            FROM (
              VALUES (timestamp without time zone {$from}, {$initial}::numeric, timestamp without time zone {$from})
            ) AS t(timestamp, difference, created_at)
        SQL;
    }

    /** Port of `end_of_period_value_sql`. */
    private function endOfPeriodValueSql(string $to): string
    {
        return <<<SQL
            SELECT *
            FROM (
              VALUES (timestamp without time zone {$to}, 0, timestamp without time zone {$to})
            ) AS t(timestamp, difference, created_at)
        SQL;
    }

    /**
     * Port of `grouped_initial_value_sql`.
     *
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     */
    private function groupedInitialValueSql(array $initialValues, string $from): string
    {
        $rows = [];
        foreach ($initialValues as $initialValue) {
            $rows[] = '('.implode(', ', [
                ...$this->quotedGroupValues($initialValue),
                'timestamp without time zone '.$from,
                $this->store->quote($initialValue['value']).'::numeric',
                'timestamp without time zone '.$from,
            ]).')';
        }

        $names = $this->groupNames();

        return 'SELECT * FROM (VALUES '.implode(', ', $rows).") AS t({$names}, timestamp, difference, created_at)";
    }

    /**
     * Port of `grouped_end_of_period_value_sql`.
     *
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     */
    private function groupedEndOfPeriodValueSql(array $initialValues, string $to): string
    {
        $rows = [];
        foreach ($initialValues as $initialValue) {
            $rows[] = '('.implode(', ', [
                ...$this->quotedGroupValues($initialValue),
                'timestamp without time zone '.$to,
                '0',
                'timestamp without time zone '.$to,
            ]).')';
        }

        $names = $this->groupNames();

        return 'SELECT * FROM (VALUES '.implode(', ', $rows).") AS t({$names}, timestamp, difference, created_at)";
    }

    /**
     * Port of `quoted_group_values` — the group columns of one initial
     * value, NULL when absent.
     *
     * @param  array{groups: array<string, mixed>, value: string|int}  $initialValue
     * @return list<string>
     */
    private function quotedGroupValues(array $initialValue): array
    {
        $values = [];
        foreach ($this->store->groupedBy() as $group) {
            $value = $initialValue['groups'][$group] ?? null;
            $values[] = ($value === null || $value === '') ? 'NULL' : $this->store->quote((string) $value);
        }

        return $values;
    }

    /**
     * The ordered events scope carrying the weighted property — and, in the
     * grouped variant, the aliased group columns (g_0..gN) the CTE
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
                '('.$this->store->propertyName().')::numeric AS difference',
                $this->store->createdAtOrderingColumn(),
            ]));
        }

        return $this->eventsSubquery;
    }

    /** Port of `period_ratio_sql`. */
    private function periodRatioSql(string $to): string
    {
        $chargesDuration = $this->store->chargesDuration();
        $durationSeconds = ((int) ($chargesDuration ?? 0)) * 86400;

        return <<<SQL
            -- NOTE: duration in seconds between current event and next one - or end of period if next event is null
            CASE WHEN EXTRACT(EPOCH FROM LEAD(timestamp, 1, {$to}) OVER (ORDER BY timestamp) - timestamp) = 0
            THEN
              0 -- NOTE: duration was null so usage is null
            ELSE
              -- NOTE: cumulative sum from previous events in the period
              (SUM(difference) OVER (ORDER BY timestamp ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW))
              *
              -- NOTE: duration in seconds between current event and next one - or end of period if next event is null
              EXTRACT(EPOCH FROM LEAD(timestamp, 1, {$to}) OVER (ORDER BY timestamp) - timestamp)
              /
              -- NOTE: full duration of the period
              {$durationSeconds}
            END
        SQL;
    }

    private function groupedPeriodRatioSql(string $to): string
    {
        $chargesDuration = $this->store->chargesDuration();
        $durationSeconds = ((int) ($chargesDuration ?? 0)) * 86400;
        $names = $this->groupNames();

        return <<<SQL
            -- NOTE: duration in seconds between current event and next one - or end of period if next event is null
            CASE WHEN EXTRACT(EPOCH FROM LEAD(timestamp, 1, {$to}) OVER (PARTITION BY {$names} ORDER BY timestamp) - timestamp) = 0
            THEN
              0
            ELSE
              (SUM(difference) OVER (PARTITION BY {$names} ORDER BY timestamp ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW))
              *
              EXTRACT(EPOCH FROM LEAD(timestamp, 1, {$to}) OVER (PARTITION BY {$names} ORDER BY timestamp) - timestamp)
              /
              {$durationSeconds}
            END
        SQL;
    }

    private function groupNames(): string
    {
        return implode(', ', array_map(
            fn (int $index) => 'g_'.$index,
            array_keys(array_values($this->store->groupedBy())),
        ));
    }
}
