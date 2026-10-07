<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

use App\Models\Event;
use App\Services\ClickHouse\Client;
use App\Services\Events\Stores\ClickHouse\UniqueCountQuery;
use App\Services\Events\Stores\ClickHouse\WeightedSumQuery;

/**
 * Port of Rails' Events::Stores::ClickhouseStore
 * (app/services/events/stores/clickhouse_store.rb) — aggregations over the
 * ClickHouse `events_enriched` ReplacingMergeTree through the HTTP client
 * (App\Services\ClickHouse\Client). Selected by StoreFactory only when
 * LAGO_CLICKHOUSE_ENABLED is set AND the organization opted into the
 * clickhouse events store.
 *
 * ClickHouse cannot guarantee that events_enriched is deduplicated at rest,
 * so every aggregation reads through the `FINAL` modifier
 * (deduplicatedEventsSql): the ReplacingMergeTree collapses rows sharing
 * the ORDER BY key (organization_id, code, external_subscription_id,
 * toDate(timestamp), timestamp, transaction_id) at read time, keeping the
 * row from the most recent part. SQL semantics — CTE shapes, FINAL,
 * lagInFrame windows, toDecimal casts — are matched 1:1.
 *
 * Like Rails, string literals (property names, boundaries, timezones) are
 * embedded as quoted SQL literals; the clickhouse-activerecord adapter
 * returns every column as a string, so value coercion (int casts,
 * BigDecimal()) happens on the PHP side exactly where Rails does it.
 */
class ClickHouseStore extends BaseStore
{
    /** CLICKHOUSE_MERGE_DELAY: give CH time to consume the enriched event. */
    public const MERGE_DELAY_SECONDS = 15;

    /** DEDUP_KEY_COLUMNS — the ReplacingMergeTree sorting key. */
    public const DEDUP_KEY_COLUMNS = [
        'organization_id',
        'code',
        'external_subscription_id',
        'transaction_id',
        'timestamp',
    ];

    /** ClickhouseSqlHelpers::DECIMAL_SCALE. */
    public const DECIMAL_SCALE = 26;

    /** ClickhouseSqlHelpers::DECIMAL_DATE_SCALE. */
    public const DECIMAL_DATE_SCALE = 10;

    protected Client $client;

    public function __construct(
        mixed $billingContext,
        array $boundaries = [],
        ?string $code = null,
        array $filters = [],
        bool $deduplicate = false,
    ) {
        parent::__construct($billingContext, $boundaries, $code, $filters, $deduplicate);

        $this->client = new Client;
    }

    // -- Core scope -----------------------------------------------------------

    /**
     * Port of `events(force_from:, ordered:)` — the deduplicated (or plain)
     * event rows as arrays. Rails returns a relation consumed via pluck /
     * .last / iteration; the port materializes the rows.
     *
     * @return list<array<string, mixed>>
     */
    public function events(bool $forceFrom = false, bool $ordered = false): array
    {
        $sql = $this->withCtes(
            $this->eventsCteQueries(
                forceFrom: $forceFrom,
                ordered: $ordered,
                select: ['*'],
                deduplicatedColumns: ['value', 'decimal_value', 'properties', 'precise_total_amount_cents'],
            ),
            'SELECT * FROM events'.($ordered ? ' ORDER BY events.timestamp ASC' : ''),
        );

        return $this->client->selectRows($sql);
    }

    /**
     * Port of `events_cte_queries(**args)` — the WITH fragments the
     * aggregations embed: the (optionally deduplicated) `events_enriched`
     * source and the filtered/ordered `events` selection.
     *
     * @param  list<string>  $select
     * @param  list<string>  $deduplicatedColumns
     * @return array<string, string> CTE name => SQL
     */
    public function eventsCteQueries(
        bool $forceFrom = false,
        bool $ordered = false,
        array $select = ['*'],
        array $deduplicatedColumns = [],
    ): array {
        if (! $this->deduplicate) {
            return [
                'events' => $this->eventsCteSqlWithoutDeduplication($forceFrom, $ordered, $select),
            ];
        }

        $orderColumn = in_array('decimal_value', $deduplicatedColumns, true) ? 'decimal_value' : 'value';
        if ($ordered) {
            $deduplicatedColumns[] = $orderColumn;
        }

        $eventsFrom = ($forceFrom || $this->useFromBoundary) ? $this->fromDatetime() : null;
        $eventsTo = $this->applicableToDatetime();

        return [
            'events_enriched' => $this->deduplicatedEventsSql(
                fromDatetime: $eventsFrom,
                toDatetime: $eventsTo,
                deduplicatedColumns: $deduplicatedColumns,
            ),
            'events' => $this->eventsCteSqlWithDeduplication($ordered, $select, $orderColumn),
        ];
    }

    /**
     * Port of `deduplicated_events_sql` — the FINAL dedup read. Grouping
     * and filtering are made based on the properties, so the properties
     * column joins the deduplicated set when any of them is active.
     *
     * @param  list<string>  $deduplicatedColumns
     */
    public function deduplicatedEventsSql(
        mixed $fromDatetime,
        mixed $toDatetime,
        array $deduplicatedColumns = [],
    ): string {
        $columns = $deduplicatedColumns;

        if (
            $this->groupedBy !== []
            || $this->groupedByValuesExists()
            || $this->matchingFilters !== []
            || $this->ignoredFilters !== []
        ) {
            $columns[] = 'properties';
        }

        $selectedColumns = implode(', ', array_values(array_unique([...self::DEDUP_KEY_COLUMNS, ...$columns])));

        return 'SELECT '.$selectedColumns.' FROM events_enriched FINAL WHERE '
            .$this->deduplicatedEventsWhereSql($fromDatetime, $toDatetime);
    }

    // -- Value listings -------------------------------------------------------

    /** Port of `events_values(limit:, force_from:, exclude_event:)`. */
    public function eventsValues(?int $limit = null, bool $forceFrom = false, bool $excludeEvent = false): array
    {
        $sql = $this->withCtes(
            $this->eventsCteQueries(forceFrom: $forceFrom, ordered: true),
            'SELECT events.decimal_value FROM events',
        );

        if ($excludeEvent && $this->event !== null) {
            $sql .= ' WHERE events.transaction_id != '.$this->quote($this->event->transaction_id);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT '.$limit;
        }

        return array_map(
            static fn (array $row): mixed => $row['decimal_value'],
            $this->client->selectRows($sql),
        );
    }

    /** Port of `last_event` — the newest event in the scope. */
    public function lastEvent(): ?array
    {
        $rows = $this->events(ordered: true);

        return $rows === [] ? null : $rows[count($rows) - 1];
    }

    /** Port of `prorated_events_values(total_duration)`. */
    public function proratedEventsValues(int|string $totalDuration): array
    {
        // The ratio multiplies the OUTER selection over the `events` CTE,
        // so the timestamp field ref is `events.` (Rails plucks
        // `events_enriched.decimal_value * (ratio)` off a scope whose FROM
        // is the dedup subquery aliased events_enriched).
        $ratioSql = $this->durationRatioSql('events.timestamp', $this->toDatetime(), $totalDuration, $this->timezone());

        $sql = $this->withCtes(
            $this->eventsCteQueries(ordered: true),
            'SELECT events.decimal_value * ('.$ratioSql.') FROM events',
        );

        return array_map(
            static fn (array $row): mixed => array_values($row)[0],
            $this->client->selectRows($sql),
        );
    }

    // -- count ----------------------------------------------------------------

    public function count(): AggregationResult
    {
        $value = $this->client->selectValue($this->countQuery());

        return $this->buildAggregationResultFromValue((string) ($value ?? '0'));
    }

    /**
     * Port of `count_query` — counting deduplicated events only needs the
     * number of distinct dedup keys, so an unfiltered count reads
     * events_enriched FINAL directly; a filtered count keeps the CTE path
     * so the charge filters still apply to the deduplicated rows.
     */
    public function countQuery(): string
    {
        $filtered = $this->groupedByValuesExists()
            || $this->matchingFilters !== []
            || $this->ignoredFilters !== [];

        if (! $this->deduplicate || $filtered) {
            return $this->withCtes(
                $this->eventsCteQueries(deduplicatedColumns: ['value']),
                'SELECT count() FROM events',
            );
        }

        $eventsFrom = $this->useFromBoundary ? $this->fromDatetime() : null;

        return 'SELECT count() FROM events_enriched FINAL WHERE '
            .$this->deduplicatedEventsWhereSql($eventsFrom, $this->applicableToDatetime());
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedCount(?array $columns = null): array
    {
        $columns ??= $this->groupedBy;
        [$groups, $groupNames] = $this->groupedColumns($columns);

        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: [...$groups, 'events_enriched.transaction_id'],
                deduplicatedColumns: ['value', 'properties'],
            ),
            'SELECT '.$groupNames.', toDecimal32(count(), 0) FROM events GROUP BY '.$groupNames,
        );

        return $this->groupedResultsWithValueAsCount(
            $this->prepareGroupedResult($this->client->selectRows($sql), columns: $columns),
        );
    }

    // -- unique count ---------------------------------------------------------

    public function uniqueCount(): AggregationResult
    {
        $query = new UniqueCountQuery($this);

        $row = $this->client->selectOne($this->bindParams($query->query(), [
            'decimal_date_scale' => self::DECIMAL_DATE_SCALE,
        ]));

        return $this->buildAggregationResultFromValue((string) ($row['aggregation'] ?? '0'));
    }

    public function proratedUniqueCount(): AggregationResult
    {
        $query = new UniqueCountQuery($this);

        $sql = $this->bindParams($query->proratedQuery(), [
            'from_datetime' => $this->datetimeLiteral($this->fromDatetime(), floorToMilliseconds: true),
            'to_datetime' => $this->datetimeLiteral($this->toDatetime()),
            'decimal_date_scale' => self::DECIMAL_DATE_SCALE,
            'timezone' => $this->timezone(),
        ]);

        $row = $this->client->selectOne($sql);

        return $this->buildAggregationResultFromValue((string) ($row['aggregation'] ?? '0'));
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedUniqueCount(?array $columns = null): array
    {
        // Rails: `dup` — a clone keeps the current store untouched while the
        // group columns are associated with the copy.
        $duplicated = clone $this;
        $duplicated->setGroupedBy($columns ?? $this->groupedBy);

        $query = new UniqueCountQuery($duplicated);

        $sql = $this->bindParams($query->groupedQuery(), [
            'to_datetime' => $this->datetimeLiteral($this->toDatetime()),
            'decimal_date_scale' => self::DECIMAL_DATE_SCALE,
        ]);

        $rows = $this->client->selectRows($sql);

        return $this->groupedResultsWithValueAsCount(
            $this->prepareGroupedResult($rows, columns: $columns ?? $this->groupedBy),
        );
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedProratedUniqueCount(): array
    {
        $query = new UniqueCountQuery($this);

        $sql = $this->bindParams($query->groupedProratedQuery(), [
            'from_datetime' => $this->datetimeLiteral($this->fromDatetime(), floorToMilliseconds: true),
            'to_datetime' => $this->datetimeLiteral($this->toDatetime()),
            'decimal_date_scale' => self::DECIMAL_DATE_SCALE,
            'timezone' => $this->timezone(),
        ]);

        return $this->groupedResultsWithValueAsCount(
            $this->prepareGroupedResult($this->client->selectRows($sql)),
        );
    }

    // -- max / last -----------------------------------------------------------

    public function max(bool $withCount = true): AggregationResult
    {
        $sql = $this->withCtes(
            $this->eventsCteQueries(deduplicatedColumns: ['decimal_value']),
            'SELECT max(events.decimal_value) as value, '.($withCount ? 'count()' : 'null').' as events_count FROM events',
        );

        return $this->buildAggregationResult($this->client->selectOne($sql));
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedMax(?array $columns = null, bool $withCount = true): array
    {
        $columns ??= $this->groupedBy;
        [$groups, $groupNames] = $this->groupedColumns($columns);

        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: [...$groups, 'events_enriched.decimal_value AS property', 'events_enriched.timestamp'],
                deduplicatedColumns: ['decimal_value', 'properties'],
            ),
            'SELECT '.$groupNames.', MAX(property), '.($withCount ? 'count()' : 'null')
                .' FROM events GROUP BY '.$groupNames,
        );

        return $this->prepareGroupedAggregatedValues($this->client->selectRows($sql), columns: $columns);
    }

    public function last(bool $withCount = true): AggregationResult
    {
        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: ['events_enriched.decimal_value AS property', 'events_enriched.timestamp'],
                deduplicatedColumns: ['decimal_value', 'properties'],
            ),
            'SELECT property as value, '.($withCount ? 'count() OVER ()' : 'null')
                .' as events_count FROM events ORDER BY events.timestamp DESC LIMIT 1',
        );

        return $this->buildLastAggregationResult($this->client->selectOne($sql), withCount: $withCount);
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedLast(?array $columns = null, bool $withCount = true): array
    {
        $columns ??= $this->groupedBy;
        [$groups, $groupNames] = $this->groupedColumns($columns);
        $distinctOnNames = ($this->groupedBy !== []) ? $this->groupedColumns($this->groupedBy)[1] : null;

        if ($distinctOnNames !== null) {
            $countSelect = $withCount ? 'count() OVER (PARTITION BY '.$distinctOnNames.')' : 'null';

            $sql = $this->withCtes(
                $this->eventsCteQueries(
                    select: [...$groups, 'events_enriched.decimal_value AS property', 'events_enriched.timestamp'],
                    deduplicatedColumns: ['decimal_value', 'properties'],
                ),
                'SELECT DISTINCT ON ('.$distinctOnNames.') '.$groupNames.', property, '
                    .$countSelect.' as events_count FROM events ORDER BY '.$distinctOnNames.', events.timestamp DESC',
            );
        } else {
            $countSelect = $withCount ? 'count() OVER ()' : 'null';

            $sql = $this->withCtes(
                $this->eventsCteQueries(
                    select: [...$groups, 'events_enriched.decimal_value AS property', 'events_enriched.timestamp'],
                    deduplicatedColumns: ['decimal_value', 'properties'],
                ),
                'SELECT '.$groupNames.', property, '.$countSelect.' as events_count FROM events'
                    .' ORDER BY events.timestamp DESC LIMIT 1',
            );
        }

        return $this->prepareGroupedAggregatedValues($this->client->selectRows($sql), columns: $columns);
    }

    // -- sum ------------------------------------------------------------------

    public function sumPreciseTotalAmountCents(): string
    {
        $sql = $this->withCtes(
            $this->eventsCteQueries(deduplicatedColumns: ['precise_total_amount_cents']),
            'SELECT COALESCE(SUM(events.precise_total_amount_cents), 0) FROM events',
        );

        $value = $this->client->selectValue($sql);

        return (string) (($value === null || $value === '') ? '0' : $value);
    }

    /** @return list<array{groups: array<string, mixed>, value: mixed}> */
    public function groupedSumPreciseTotalAmountCents(): array
    {
        [$groups, $groupNames] = $this->groupedColumns();

        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: [...$groups, 'events_enriched.precise_total_amount_cents AS precise_total_amount_cents'],
                deduplicatedColumns: ['precise_total_amount_cents'],
            ),
            'SELECT '.$groupNames.', sum(events.precise_total_amount_cents) FROM events GROUP BY '.$groupNames,
        );

        return $this->prepareGroupedResult($this->client->selectRows($sql), decimal: true);
    }

    public function sum(bool $withCount = true): AggregationResult
    {
        $sql = $this->withCtes(
            $this->eventsCteQueries(deduplicatedColumns: ['decimal_value']),
            'SELECT sum(events.decimal_value) as value, '.($withCount ? 'count()' : 'null').' as events_count FROM events',
        );

        return $this->buildAggregationResult($this->client->selectOne($sql));
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedSum(?array $columns = null, bool $withCount = true): array
    {
        $columns ??= $this->groupedBy;
        [$groups, $groupNames] = $this->groupedColumns($columns);

        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: [...$groups, 'events_enriched.decimal_value AS property'],
                deduplicatedColumns: ['decimal_value', 'properties'],
            ),
            'SELECT '.$groupNames.', sum(events.property), '.($withCount ? 'count()' : 'null')
                .' FROM events GROUP BY '.$groupNames,
        );

        return $this->prepareGroupedAggregatedValues($this->client->selectRows($sql), columns: $columns);
    }

    public function proratedSum(int|string $periodDuration, int|string|null $persistedDuration = null): ProratedAggregationResult
    {
        $ratio = $persistedDuration !== null
            ? $this->floatRatio($persistedDuration, $periodDuration)
            : $this->durationRatioSql('events_enriched.timestamp', $this->toDatetime(), $periodDuration, $this->timezone());

        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: ['events_enriched.decimal_value', 'events_enriched.decimal_value * ('.$ratio.') AS prorated_value'],
                deduplicatedColumns: ['decimal_value'],
            ),
            'SELECT sum(events.prorated_value) as prorated_value, sum(events.decimal_value) as value, count() as events_count FROM events',
        );

        return $this->buildProratedAggregationResult($this->client->selectOne($sql));
    }

    /** @return list<GroupedProratedAggregationResult> */
    public function groupedProratedSum(int|string $periodDuration, int|string|null $persistedDuration = null): array
    {
        [$groups, $groupNames] = $this->groupedColumns();

        $ratio = $persistedDuration !== null
            ? $this->floatRatio($persistedDuration, $periodDuration)
            : $this->durationRatioSql('events_enriched.timestamp', $this->toDatetime(), $periodDuration, $this->timezone());

        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: [
                    ...$groups,
                    'events_enriched.decimal_value',
                    'events_enriched.decimal_value * ('.$ratio.') AS prorated_value',
                ],
                deduplicatedColumns: ['decimal_value'],
            ),
            'SELECT '.$groupNames.', sum(events.prorated_value) as prorated_value, sum(events.decimal_value) as value,'
                .' count() as events_count FROM events GROUP BY '.$groupNames,
        );

        return $this->prepareGroupedProratedResult($this->client->selectRows($sql));
    }

    /** Port of `sum_date_breakdown`. */
    public function sumDateBreakdown(): array
    {
        $dateField = $this->dateInCustomerTimezoneSql('events_enriched.timestamp', $this->timezone());

        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: ['toDate('.$dateField.') AS day', 'events_enriched.decimal_value AS property'],
                deduplicatedColumns: ['decimal_value'],
            ),
            'SELECT events.day, sum(events.property) AS day_sum FROM events GROUP BY events.day ORDER BY events.day asc',
        );

        return array_map(
            static fn (array $row): array => ['date' => (string) array_values($row)[0], 'value' => array_values($row)[1]],
            $this->client->selectRows($sql),
        );
    }

    // -- weighted sum ---------------------------------------------------------

    public function weightedSum(string|int|float|null $initialValue = 0): WeightedAggregationResult
    {
        $query = new WeightedSumQuery($this);

        $sql = $this->bindParams($query->query(), [
            'from_datetime' => $this->datetimeLiteral($this->fromDatetime(), floorToMilliseconds: true),
            'to_datetime' => $this->datetimeLiteral($this->toDatetimeCeiled()),
            'decimal_scale' => self::DECIMAL_SCALE,
            'initial_value' => $this->decimalLiteral((string) ($initialValue ?? 0)),
        ]);

        $row = $this->client->selectOne($sql) ?? [];

        return $this->buildWeightedAggregationResult(
            value: (string) (($row['aggregation'] ?? '') === '' ? '0' : $row['aggregation']),
            variationWithInitial: (string) (($row['variation_with_initial'] ?? '') === '' ? '0' : $row['variation_with_initial']),
            rowsCount: (int) ($row['rows_count'] ?? 0),
            initialValue: $initialValue,
        );
    }

    /**
     * Port of `grouped_weighted_sum(columns:, initial_value:, initial_values:)`.
     *
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     * @return list<GroupedWeightedAggregationResult>
     */
    public function groupedWeightedSum(?array $columns = null, array $initialValues = [], string|int|float|null $initialValue = 0): array
    {
        $columns ??= $this->groupedBy;

        $duplicated = clone $this;
        $duplicated->setGroupedBy($columns);

        // Rails: initial_values.present? ? initial_values : (initial_value.nonzero? ? [{groups: {}, value: initial_value}] : []).
        $baselineInitialValues = $initialValues;
        if ($baselineInitialValues === [] && (string) $initialValue !== '0' && $initialValue !== null) {
            $baselineInitialValues = [['groups' => [], 'value' => $initialValue]];
        }

        $formattedInitialValues = $duplicated->formattedWeightedSumInitialValues($baselineInitialValues);
        if ($formattedInitialValues === []) {
            return [];
        }

        $query = new WeightedSumQuery($duplicated);

        $sql = $this->bindParams(
            $query->groupedQuery($formattedInitialValues),
            [
                'from_datetime' => $this->datetimeLiteral($this->fromDatetime(), floorToMilliseconds: true),
                'to_datetime' => $this->datetimeLiteral($this->toDatetimeCeiled()),
                'decimal_scale' => self::DECIMAL_SCALE,
            ],
        );

        return $this->prepareGroupedWeightedValues(
            $this->client->selectRows($sql),
            $formattedInitialValues,
            columns: $columns,
        );
    }

    /**
     * Port of `formatted_weighted_sum_initial_values` — the initial value
     * for each group present in the period's events.
     *
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     * @return list<array{groups: array<string, mixed>, value: string|int}>
     */
    public function formattedWeightedSumInitialValues(array $initialValues): array
    {
        $formatted = [];
        foreach ($this->groupedCount() as $group) {
            $value = 0;
            foreach ($initialValues as $initialValue) {
                if ($initialValue['groups'] === $group->groups) {
                    $value = $initialValue['value'];

                    break;
                }
            }

            $formatted[] = ['groups' => $group->groups, 'value' => $value];
        }

        foreach ($initialValues as $initialValue) {
            $found = false;
            foreach ($formatted as $entry) {
                if ($entry['groups'] === $initialValue['groups']) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                $formatted[] = $initialValue;
            }
        }

        return $formatted;
    }

    // -- distinct combinations (charge filters / billing period pre-filtering) -

    /**
     * Port of `distinct_codes_and_property_combinations` — the distinct
     * [code, properties, last_seen_at] combinations present in the period's
     * events; only the filter_keys dimensions are kept (an empty combination
     * is the default, no-filter bucket). ClickHouse stores properties as
     * Map(String, String): a missing key reads back as an empty string, so
     * blank values are dropped to mirror the Postgres jsonb behaviour.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $filterKeys
     * @return list<array{code: string, combination: array<string, mixed>, last_seen_at: ?string}>
     */
    public function distinctCodesAndPropertyCombinations(
        array $codes,
        array $filterKeys,
        bool $includeAllHistory = false,
        bool $withLastSeenAt = true,
    ): array {
        if ($codes === []) {
            return [];
        }

        $conditions = [
            'external_subscription_id = '.$this->quote($this->billingContext->externalId()),
            'organization_id = '.$this->quote($this->billingContext->organizationId()),
            'code IN ('.implode(', ', array_map($this->quote(...), $codes)).')',
            'events_enriched.timestamp <= '.$this->datetimeLiteral($this->applicableToDatetime()),
        ];

        if (! $includeAllHistory) {
            $from = $this->fromDatetime();
            if ($from !== null) {
                $conditions[] = 'events_enriched.timestamp >= '.$this->datetimeLiteral($from, floorToMilliseconds: true);
            }
        }

        $selects = ['code AS code'];
        $groupColumns = ['code'];
        foreach (array_values($filterKeys) as $index => $key) {
            $selects[] = 'properties['.$this->quote((string) $key)."] AS prop_{$index}";
            $groupColumns[] = "prop_{$index}";
        }
        $selects[] = $withLastSeenAt ? 'MAX(enriched_at) AS last_seen_at' : 'NULL AS last_seen_at';

        $sql = 'SELECT '.implode(', ', $selects).' FROM events_enriched WHERE '
            .implode(' AND ', $conditions).' GROUP BY '.implode(', ', $groupColumns);

        $combinations = [];
        foreach ($this->client->selectRows($sql) as $row) {
            $combination = [];
            foreach (array_values($filterKeys) as $index => $key) {
                $value = $row["prop_{$index}"] ?? null;
                if ($value !== null && $value !== '') {
                    $combination[$key] = $value;
                }
            }

            $combinations[] = [
                'code' => (string) $row['code'],
                'combination' => $combination,
                'last_seen_at' => isset($row['last_seen_at']) && $row['last_seen_at'] !== null
                    ? (string) $row['last_seen_at']
                    : null,
            ];
        }

        return $combinations;
    }

    /** Port of `active_unique_property?`. */
    public function activeUniqueProperty(Event $event): bool
    {
        $property = $this->sanitizedPropertyName($this->aggregationProperty);

        $sql = $this->withCtes(
            $this->eventsCteQueries(
                select: ['*'],
                deduplicatedColumns: ['value', 'decimal_value', 'properties', 'precise_total_amount_cents'],
            ),
            'SELECT * FROM events'
                .' WHERE '.$property.' = '
                    .$this->quote((string) ($event->properties[$this->aggregationProperty] ?? ''))
                .' AND events.timestamp < '.$this->datetimeLiteral($event->timestamp)
                .' ORDER BY events.timestamp DESC LIMIT 1',
        );

        $previousEvent = $this->client->selectOne($sql);

        if ($previousEvent === null) {
            return false;
        }

        $properties = is_array($previousEvent['properties'] ?? null)
            ? $previousEvent['properties']
            : (json_decode((string) ($previousEvent['properties'] ?? ''), true) ?? []);

        $operationType = $properties['operation_type'] ?? null;

        return $operationType === null || $operationType === 'add';
    }

    // -- SQL helpers (Rails: ClickhouseSqlHelpers & co) ------------------------

    /** Port of `sanitized_property_name` — a quoted literal subscript. */
    public function sanitizedPropertyName(?string $property = null): string
    {
        return 'events_enriched.properties['.$this->quote((string) ($property ?? $this->aggregationProperty)).']';
    }

    /** Port of `operation_type_sql`. */
    public function operationTypeSql(): string
    {
        return "events_enriched.sorted_properties['operation_type']";
    }

    /** Port of `charges_duration` (the weighted-sum denominator). */
    public function chargesDuration(): mixed
    {
        return $this->boundaries['charges_duration'] ?? null;
    }

    /**
     * Port of `upper_timestamp_boundary_sql` — when the boundary is the
     * pay-in-advance event's own timestamp, tie-break events sharing it by
     * transaction_id so each gets a distinct position.
     */
    public function upperTimestampBoundarySql(mixed $toDatetime, string $prefix = ''): string
    {
        $boundaryTransactionId = null;
        if ($toDatetime === ($this->boundaries['max_timestamp'] ?? null) && $this->event !== null) {
            $boundaryTransactionId = $this->event->transaction_id;
        }

        $literal = $this->datetimeLiteral($toDatetime);

        if ($boundaryTransactionId !== null) {
            return '('.$prefix.'timestamp < '.$literal
                .' OR ('.$prefix.'timestamp = '.$literal
                .' AND '.$prefix.'transaction_id <= '.$this->quote($boundaryTransactionId).'))';
        }

        return $prefix.'timestamp <= '.$literal;
    }

    /**
     * Port of `duration_ratio_sql` — pro-rata of the duration in days
     * between the datetimes over the duration of the billing period, dates
     * in the customer timezone.
     */
    public function durationRatioSql(string $from, mixed $to, int|string $duration, string $timezone): string
    {
        $fromInTimezone = $this->dateInCustomerTimezoneSql($from, $timezone);
        $toInTimezone = $this->dateInCustomerTimezoneSql($this->datetimeLiteral($to), $timezone);

        return "(date_diff('days', {$fromInTimezone}, {$toInTimezone}) + 1) / {$duration}";
    }

    /** Port of `date_in_customer_timezone_sql`. */
    public function dateInCustomerTimezoneSql(string $dateValue, string $timezone): string
    {
        if (str_contains($dateValue, "'")) {
            // A quoted datetime literal: toTimezone(toDateTime64(:date, 5, 'UTC'), :timezone).
            return "toTimezone(toDateTime64({$dateValue}, 5, 'UTC'), '".$timezone."')";
        }

        // A table field name, e.g. events_enriched.timestamp.
        return 'toTimezone('.$dateValue.", '".$timezone."')";
    }

    /**
     * Port of `decimal_literal` — a numeric literal rendered as a
     * fixed-point string so toDecimal128 reads an exact decimal instead of
     * a precision-losing Float64.
     */
    public function decimalLiteral(string|int|float $value): string
    {
        $fixed = $value;
        if (! str_contains((string) $value, '.')) {
            $fixed = ((string) $value).'.0';
        }

        return (string) $fixed;
    }

    public function timezone(): string
    {
        return (string) $this->customer()->applicableTimezone();
    }

    /** Quoted SQL string literal (ClickHouse's SQL dialect escapes '). */
    public function quote(mixed $value): string
    {
        return "'".str_replace("'", "''", (string) $value)."'";
    }

    /**
     * UTC datetime literal. `$floorToMilliseconds` mirrors Rails'
     * `to_time.floor(3)`.
     */
    public function datetimeLiteral(mixed $datetime, bool $floorToMilliseconds = false): string
    {
        $carbon = \Illuminate\Support\Facades\Date::parse($datetime)->utc();

        if ($floorToMilliseconds) {
            $carbon->microsecond = (int) (floor($carbon->microsecond / 1000) * 1000);
        }

        return $this->quote($carbon->format('Y-m-d H:i:s').'.'.sprintf('%03d', (int) ($carbon->microsecond / 1000)));
    }

    /** Port of `with_ctes`. @param array<string, string> $ctes */
    public function withCtes(array $ctes, string $sql): string
    {
        if ($ctes === []) {
            return $sql;
        }

        $fragments = [];
        foreach ($ctes as $name => $cteSql) {
            $fragments[] = $name.' AS ('.$cteSql.')';
        }

        return 'WITH '.implode(', ', $fragments).' '.$sql;
    }

    /**
     * Port of the `sanitize_colon` + sanitize_sql_for_conditions pair —
     * :named placeholders (including the ones inside ClickHouse toDateTime64
     * literals) replaced with quoted literals / scalar params.
     *
     * @param  array<string, mixed>  $params
     */
    public function bindParams(string $sql, array $params): string
    {
        // Longest names first so a prefix (:to_datetime) never shadows a
        // longer one.
        $names = array_keys($params);
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($names as $name) {
            $value = $params[$name];
            $literal = match (true) {
                is_int($value) => (string) $value,
                // An already-quoted literal (datetimeLiteral(...) results are
                // passed through untouched).
                is_string($value) && str_starts_with($value, "'") && str_ends_with($value, "'") && mb_strlen($value) > 1 => $value,
                is_string($value) => $this->quote($value),
                $value === null => 'NULL',
                default => $this->quote((string) $value),
            };

            $sql = preg_replace('/:'.$name.'\b/', $literal, $sql) ?? $sql;
        }

        return $sql;
    }

    /** Port of `grouped_arel_columns` — [select expressions, "g_0, g_1"]. */
    public function groupedColumns(?array $columns = null): array
    {
        $columns ??= $this->groupedBy;

        $selects = [];
        $names = [];
        foreach (array_values($columns) as $index => $column) {
            $selects[] = $this->sanitizedPropertyName((string) $column).' AS g_'.$index;
            $names[] = 'g_'.$index;
        }

        return [$selects, implode(', ', $names)];
    }

    /** Port of `grouped_by_columns` — the quoted group values of an initial value row. */
    public function groupedByColumns(array $values): array
    {
        return array_map(
            fn (string $group): string => $this->quote($values[$group] ?? ''),
            $this->groupedBy,
        );
    }

    /** Port of `grouped_by_count`. */
    public function groupedByCount(): int
    {
        return count($this->groupedBy);
    }

    /**
     * Port of `with_presentation_by_in_grouped_by?` — true when the
     * grouped_by includes the presentation_by, deciding between
     * sorted_grouped_by and sorted_properties in the grouped weighted-sum
     * dedup columns.
     */
    public function withPresentationByInGroupedBy(): bool
    {
        if ($this->groupedBy === []) {
            return false;
        }

        $presentationBy = $this->filters['presentation_by'] ?? null;

        return $presentationBy !== null && $presentationBy !== []
            && count(array_intersect($this->groupedBy, (array) $presentationBy)) > 0;
    }

    // -- Row preparation (Rails: prepare_grouped_result & co) -------------------

    /**
     * Port of `prepare_grouped_result` — [{ groups: {...}, value: ... }].
     *
     * @return list<array{groups: array<string, mixed>, value: mixed, timestamp?: mixed}>
     */
    protected function prepareGroupedResult(array $rows, bool $timestamp = false, bool $decimal = false, ?array $columns = null): array
    {
        $columns ??= $this->groupedBy;

        return array_map(function (array $row) use ($timestamp, $decimal, $columns) {
            $values = array_values($row);
            $lastGroup = $timestamp ? -2 : -1;

            $result = [
                'groups' => $this->buildGroups(array_slice($values, 0, $lastGroup), columns: $columns),
                'value' => $decimal
                    ? (string) (($values[count($values) - 1] ?? '') === '' ? '0' : $values[count($values) - 1])
                    : $values[count($values) - 1],
            ];

            if ($timestamp) {
                $result['timestamp'] = $values[count($values) - 2];
            }

            return $result;
        }, $rows);
    }

    /**
     * Port of `prepare_grouped_aggregated_values` — the last two columns of
     * each row are the aggregated value and the events count.
     *
     * @return list<GroupedAggregationResult>
     */
    protected function prepareGroupedAggregatedValues(array $rows, ?array $columns = null): array
    {
        $columns ??= $this->groupedBy;

        return array_map(function (array $row) use ($columns) {
            $values = array_values($row);

            return new GroupedAggregationResult(
                groups: $this->buildGroups(array_slice($values, 0, count($values) - 2), columns: $columns),
                value: $values[count($values) - 2],
                eventsCount: self::presenceToInt($values[count($values) - 1]),
            );
        }, $rows);
    }

    /**
     * Port of `prepare_grouped_prorated_result` — the last three columns of
     * each row are the prorated value, the non-prorated value and the
     * events count.
     *
     * @return list<GroupedProratedAggregationResult>
     */
    protected function prepareGroupedProratedResult(array $rows, ?array $columns = null): array
    {
        $columns ??= $this->groupedBy;

        return array_map(function (array $row) use ($columns) {
            $values = array_values($row);

            return $this->buildGroupedProratedAggregationResult(
                groups: $this->buildGroups(array_slice($values, 0, count($values) - 3), columns: $columns),
                proratedValue: $values[count($values) - 3],
                value: $values[count($values) - 2],
                eventsCount: $values[count($values) - 1],
            );
        }, $rows);
    }

    /**
     * Port of `prepare_grouped_weighted_values` — the last three columns of
     * each row are the weighted aggregation, the sum of the differences
     * (including the initial value) and the rows count.
     *
     * @param  list<array{groups: array<string, mixed>, value: string|int}>  $initialValues
     * @return list<GroupedWeightedAggregationResult>
     */
    protected function prepareGroupedWeightedValues(array $rows, array $initialValues, ?array $columns = null): array
    {
        $columns ??= $this->groupedBy;

        return array_map(function (array $row) use ($initialValues, $columns) {
            $values = array_values($row);

            return $this->buildGroupedWeightedResult(
                groups: $this->buildGroups(array_slice($values, 0, count($values) - 3), columns: $columns),
                value: (string) (($values[count($values) - 3] ?? '') === '' ? '0' : $values[count($values) - 3]),
                variationWithInitial: (string) (($values[count($values) - 2] ?? '') === '' ? '0' : $values[count($values) - 2]),
                rowsCount: (int) $values[count($values) - 1],
                initialValues: $initialValues,
            );
        }, $rows);
    }

    // -- Scope fragments --------------------------------------------------------

    /** Port of `filters_scope` as SQL text (AND-joined conditions). */
    protected function filtersSql(): string
    {
        $conditions = [];

        foreach ($this->matchingFilters as $key => $values) {
            $list = implode(', ', array_map($this->quote(...), array_map(strval(...), $values)));
            $conditions[] = 'events_enriched.properties['.$this->quote((string) $key).'] IN ('.$list.')';
        }

        $ignored = [];
        foreach ($this->ignoredFilters as $filters) {
            if ($filters === []) {
                continue;
            }

            $clauses = [];
            foreach ($filters as $key => $values) {
                if ($values === []) {
                    continue;
                }

                $list = implode(', ', array_map($this->quote(...), array_map(strval(...), $values)));
                $clauses[] = '(coalesce(events_enriched.properties['.$this->quote((string) $key)."], '') IN (".$list.'))';
            }

            $clause = implode(' AND ', $clauses);
            if ($clause !== '') {
                $ignored[] = '('.$clause.')';
            }
        }

        if ($ignored !== []) {
            $conditions[] = 'NOT ('.implode(' OR ', $ignored).')';
        }

        return $conditions === [] ? '' : ' AND '.implode(' AND ', $conditions);
    }

    /** Port of `apply_grouped_by_values` as SQL text. */
    protected function groupedByValuesSql(): string
    {
        $conditions = [];
        foreach ($this->groupedByValues ?? [] as $groupedBy => $groupedByValue) {
            if ($groupedByValue !== null && $groupedByValue !== '') {
                $conditions[] = 'events_enriched.properties['.$this->quote((string) $groupedBy).'] = '
                    .$this->quote((string) $groupedByValue);
            } else {
                $conditions[] = 'COALESCE(events_enriched.properties['.$this->quote((string) $groupedBy)."], '') = ''";
            }
        }

        return $conditions === [] ? '' : ' AND '.implode(' AND ', $conditions);
    }

    // -- Misc -------------------------------------------------------------------

    /** Rails' `to_datetime.ceil` — ceil to the whole second. */
    protected function toDatetimeCeiled(): mixed
    {
        $to = $this->toDatetime();

        if ($to instanceof \Carbon\CarbonInterface && $to->microsecond !== 0) {
            $to = $to->copy()->addSecond()->startOfSecond();
        }

        return $to;
    }

    /** Rails' `persisted_duration.fdiv(period_duration)` — a float ratio literal. */
    protected function floatRatio(int|string $persistedDuration, int|string $periodDuration): string
    {
        return json_encode((float) $persistedDuration / (float) $periodDuration);
    }

    /**
     * @param  list<string>  $select
     */
    private function eventsCteSqlWithoutDeduplication(bool $forceFrom, bool $ordered, array $select): string
    {
        $conditions = [
            'external_subscription_id = '.$this->quote($this->billingContext->externalId()),
            'organization_id = '.$this->quote($this->billingContext->organizationId()),
            'code = '.$this->quote($this->code),
        ];

        if ($forceFrom || $this->useFromBoundary) {
            $from = $this->fromDatetime();
            if ($from !== null) {
                $conditions[] = 'events_enriched.timestamp >= '.$this->datetimeLiteral($from, floorToMilliseconds: true);
            }
        }

        $to = $this->applicableToDatetime();
        if ($to !== null) {
            $conditions[] = $this->upperTimestampBoundarySql($to, prefix: 'events_enriched.');
        }

        $sql = 'SELECT '.implode(', ', $select).' FROM events_enriched WHERE '.implode(' AND ', $conditions);

        $sql .= $this->groupedByValuesSql();
        $sql .= $this->filtersSql();

        if ($ordered) {
            $sql .= ' ORDER BY events_enriched.timestamp DESC, events_enriched.value ASC';
        }

        return $sql;
    }

    /**
     * The `events` selection over the deduplicated `events_enriched` CTE —
     * organization/subscription/code and the timestamp boundaries live in
     * the dedup CTE, the charge-filter grouping and filters on the outer
     * selection (Rails: events_cte_queries_with_deduplication).
     *
     * @param  list<string>  $select
     */
    private function eventsCteSqlWithDeduplication(bool $ordered, array $select, string $orderColumn): string
    {
        $sql = 'SELECT '.implode(', ', $select).' FROM events_enriched';

        $sql .= $this->groupedByValuesSql();
        $sql .= $this->filtersSql();

        if ($ordered) {
            $sql .= ' ORDER BY events_enriched.timestamp DESC, events_enriched.'.$orderColumn.' ASC';
        }

        return $sql;
    }

    private function deduplicatedEventsWhereSql(mixed $fromDatetime, mixed $toDatetime): string
    {
        $conditions = [
            'organization_id = '.$this->quote($this->billingContext->organizationId())
                .' AND code = '.$this->quote($this->code)
                .' AND external_subscription_id = '.$this->quote($this->billingContext->externalId()),
        ];

        if ($fromDatetime !== null) {
            $conditions[] = 'timestamp >= '.$this->datetimeLiteral($fromDatetime, floorToMilliseconds: true);
        }

        if ($toDatetime !== null) {
            $conditions[] = $this->upperTimestampBoundarySql($toDatetime);
        }

        return implode(' AND ', $conditions);
    }
}
