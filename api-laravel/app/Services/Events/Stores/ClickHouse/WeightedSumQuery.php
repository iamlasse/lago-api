<?php

declare(strict_types=1);

namespace App\Services\Events\Stores\ClickHouse;

use App\Services\Events\Stores\ClickHouseStore;

/**
 * Port of Rails' Events::Stores::Clickhouse::WeightedSumQuery
 * (app/services/events/stores/clickhouse/weighted_sum_query.rb) — the
 * time-weighted sum over the `events_data` CTE (the initial-value row UNION
 * ALL the deduplicated events UNION ALL the end-of-period zero row).
 *
 * The debug-only breakdown queries are not ported (Rails marks them "not
 * used in production").
 *
 * The :named placeholders (:from_datetime, :to_datetime, :decimal_scale)
 * are bound by the store via bindParams, exactly like Rails'
 * sanitize_colon + sanitize_sql_for_conditions.
 */
class WeightedSumQuery
{
    public function __construct(protected ClickHouseStore $store) {}

    public function query(): string
    {
        return $this->store->withCtes(
            $this->eventsCteSql(),
            'SELECT'
                .' sum(period_ratio) as aggregation,'
                .' sum(difference) as variation_with_initial,'
                .' count() as rows_count'
                .' FROM (SELECT ('.$this->periodRatioSql().') as period_ratio, difference FROM events_data)'
                .' cumulated_ratios',
        );
    }

    /**
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     */
    public function groupedQuery(array $initialValues): string
    {
        $groups = $this->joinedGroupNames();

        return $this->store->withCtes(
            $this->groupedEventsCteSql($initialValues),
            'SELECT '.$groups.','
                .' SUM(period_ratio) as aggregation,'
                .' sum(difference) as variation_with_initial,'
                .' count() as rows_count'
                .' FROM (SELECT '.$groups.', ('.$this->groupedPeriodRatioSql().') AS period_ratio, difference FROM events_data)'
                .' cumulated_ratios'
                .' GROUP BY '.$groups,
        );
    }

    // -- CTE fragments ---------------------------------------------------------

    /**
     * The `events_data` CTE: initial value UNION ALL events UNION ALL the
     * end-of-period zero row.
     *
     * @return array<string, string>
     */
    private function eventsCteSql(): array
    {
        $eventsCte = $this->store->eventsCteQueries(
            forceFrom: false,
            ordered: true,
            select: [
                'events_enriched.timestamp AS timestamp',
                'events_enriched.decimal_value AS difference',
            ],
            deduplicatedColumns: ['decimal_value'],
        );

        $eventsData = '('.$this->initialValueSql().')'
            .' UNION ALL ('.$eventsCte['events'].')'
            .' UNION ALL ('.$this->endOfPeriodValueSql().')';

        unset($eventsCte['events']);
        $eventsCte['events_data'] = $eventsData;

        return $eventsCte;
    }

    /**
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     * @return array<string, string>
     */
    private function groupedEventsCteSql(array $initialValues): array
    {
        [$groups] = $this->store->groupedColumns();

        $eventsCte = $this->store->eventsCteQueries(
            forceFrom: false,
            ordered: true,
            select: [
                ...$groups,
                'events_enriched.timestamp AS timestamp',
                'events_enriched.decimal_value AS difference',
            ],
            deduplicatedColumns: $this->store->withPresentationByInGroupedBy()
                ? ['decimal_value', 'sorted_properties']
                : ['decimal_value'],
        );

        $eventsData = '('.$this->groupedInitialValueSql($initialValues).')'
            .' UNION ALL ('.$eventsCte['events'].')'
            .' UNION ALL ('.$this->groupedEndOfPeriodValueSql($initialValues).')';

        unset($eventsCte['events']);
        $eventsCte['events_data'] = $eventsData;

        return $eventsCte;
    }

    private function initialValueSql(): string
    {
        return 'SELECT'
            ." toDateTime64(:from_datetime, 5, 'UTC') as timestamp,"
            .' toDecimal128(:initial_value, :decimal_scale) as difference';
    }

    private function endOfPeriodValueSql(): string
    {
        return 'SELECT'
            ." toDateTime64(:to_datetime, 5, 'UTC') as timestamp,"
            .' toDecimal128(0, :decimal_scale) as difference';
    }

    /**
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     */
    private function groupedInitialValueSql(array $initialValues): string
    {
        return $this->groupedBoundaryValueSql($initialValues, 'from_datetime', 'initial');
    }

    /**
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     */
    private function groupedEndOfPeriodValueSql(array $initialValues): string
    {
        return $this->groupedBoundaryValueSql($initialValues, 'to_datetime', 'zero');
    }

    /**
     * Rails: `SELECT arrayJoin([tuple(...), ...]) AS tuple` exploded into
     * `tuple.N AS g_i` columns — one boundary row per initial value.
     *
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     */
    private function groupedBoundaryValueSql(array $initialValues, string $datetimeParam, string $kind): string
    {
        $rows = [];
        foreach ($initialValues as $initialValue) {
            $groups = $this->store->groupedByColumns($initialValue['groups']);

            $difference = match ($kind) {
                'initial' => "toDecimal128('".$this->store->decimalLiteral((string) $initialValue['value'])."', :decimal_scale)",
                default => 'toDecimal32(0, 0)',
            };

            $rows[] = 'tuple('.implode(', ', [...$groups,
                "toDateTime64(:{$datetimeParam}, 5, 'UTC')",
                $difference,
            ]).')';
        }

        $groupCount = $this->store->groupedByCount();

        $selects = [];
        for ($index = 0; $index < $groupCount; $index++) {
            $selects[] = 'tuple.'.($index + 1).' AS g_'.$index;
        }
        $selects[] = 'tuple.'.($groupCount + 1).' AS timestamp';
        $selects[] = 'tuple.'.($groupCount + 2).' AS difference';

        return 'SELECT '.implode(', ', $selects)
            .' FROM ( SELECT arrayJoin(['.implode(', ', $rows).']) AS tuple )';
    }

    /**
     * Weighted by the duration in seconds until the next event (or the end
     * of period) over the full duration of the period; a null duration
     * makes the usage null.
     */
    private function periodRatioSql(): string
    {
        $duration = ((int) ($this->store->chargesDuration() ?? 0)) * 86400;

        return <<<SQL
            if(
              -- duration in seconds between current event and next one - or end of period if next event is null
              date_diff('seconds', timestamp, leadInFrame(timestamp, 1, toDateTime64(:to_datetime, 5, 'UTC')) OVER (ORDER BY timestamp ASC ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)) > 0,
              -- cumulative sum from previous events in the period
              (SUM(difference) OVER (ORDER BY timestamp ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW))
              *
              -- duration in seconds between current event and next one - or end of period if next event is null
              date_diff('seconds', timestamp, leadInFrame(timestamp, 1, toDateTime64(:to_datetime, 5, 'UTC')) OVER (ORDER BY timestamp ASC ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING))
              /
              -- full duration of the period
              {$duration}
              ,
              -- duration was null so usage is null
              0
            )
        SQL;
    }

    private function groupedPeriodRatioSql(): string
    {
        $groups = $this->joinedGroupNames();
        $duration = ((int) ($this->store->chargesDuration() ?? 0)) * 86400;

        return <<<SQL
            if(
              -- duration in seconds between current event and next one - or end of period if next event is null
              date_diff('seconds', timestamp, leadInFrame(timestamp, 1, toDateTime64(:to_datetime, 5, 'UTC')) OVER (PARTITION BY {$groups} ORDER BY timestamp ASC ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING)) > 0,
              -- cumulative sum from previous events in the period
              (SUM(difference) OVER (PARTITION BY {$groups} ORDER BY timestamp ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW))
              *
              -- duration in seconds between current event and next one - or end of period if next event is null
              date_diff('seconds', timestamp, leadInFrame(timestamp, 1, toDateTime64(:to_datetime, 5, 'UTC')) OVER (PARTITION BY {$groups} ORDER BY timestamp ASC ROWS BETWEEN CURRENT ROW AND UNBOUNDED FOLLOWING))
              /
              -- full duration of the period
              {$duration}
              ,
              -- duration was null so usage is null
              0
            )
        SQL;
    }

    private function joinedGroupNames(): string
    {
        [, $names] = $this->store->groupedColumns();

        return $names;
    }
}
