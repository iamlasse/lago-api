<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

/**
 * Port of Rails' Events::Stores::PostgresStore
 * (app/services/events/stores/postgres_store.rb) — the default events
 * store: aggregation SQL over the `events` table.
 *
 * SQL semantics are matched 1:1: property extraction
 * (`events.properties->>key`), numeric casts (`::numeric` guarded by the
 * presence + numeric-regex conditions), the from/to boundaries taken FROM
 * the fee's boundaries (from_datetime floored to milliseconds,
 * max_timestamp overriding to_datetime), the charge-filter property
 * filters, and the grouped / prorated / unique-count / weighted-sum
 * variants (Rails: UniqueCountQuery / WeightedSumQuery, ported under
 * Stores\Postgres).
 *
 * Like Rails, string literals (property names, boundaries, timezones) are
 * embedded as sanitized SQL literals; the events subqueries keep their
 * query-builder bindings.
 */
class PostgresStore extends BaseStore
{
    // -- Core scope -----------------------------------------------------------

    /**
     * Port of `events(force_from:, ordered:)`.
     *
     * @return Builder<Event>
     */
    public function events(bool $forceFrom = false, bool $ordered = false): Builder
    {
        $scope = Event::query()
            ->where('external_subscription_id', $this->billingContext->externalId())
            ->where('organization_id', $this->billingContext->organizationId())
            ->where('code', $this->code);

        if ($ordered) {
            $scope->orderBy('timestamp', 'asc');
        }

        if ($forceFrom || $this->useFromBoundary) {
            $from = $this->fromDatetime();
            if ($from !== null) {
                $scope->whereRaw('events.timestamp >= '.$this->datetimeLiteral($from, floorToMilliseconds: true));
            }
        }

        if ($this->applicableToDatetime() !== null) {
            $scope = $this->applyToBoundary($scope);
        }

        if ($this->numericProperty) {
            $scope->whereRaw($this->presenceCondition())->whereRaw($this->numericCondition());
        }

        if ($this->groupedByValuesExists()) {
            $scope = $this->applyGroupedByValues($scope);
        }

        return $this->filtersScope($scope);
    }

    // -- Value listings -------------------------------------------------------

    /** Port of `events_values(limit:, force_from:, exclude_event:)`. */
    public function eventsValues(?int $limit = null, bool $forceFrom = false, bool $excludeEvent = false): array
    {
        $propertyName = $this->propertyName();
        if ($this->numericProperty) {
            $propertyName = "({$propertyName})::numeric";
        }

        $scope = $this->events(forceFrom: $forceFrom, ordered: true);

        if ($excludeEvent && $this->event !== null) {
            $scope->where('transaction_id', '!=', $this->event->transaction_id);
        }

        if ($limit !== null) {
            $scope->limit($limit);
        }

        return $this->selectColumn($scope, $propertyName);
    }

    /** Port of `last_event` — the newest event in the scope. */
    public function lastEvent(): ?Event
    {
        return $this->newestFirstScope()->first();
    }

    /** Port of `prorated_events_values(total_duration)`. */
    public function proratedEventsValues(int|string $totalDuration): array
    {
        $ratioSql = $this->durationRatioSql('events.timestamp', $this->toDatetime(), $totalDuration);

        return $this->selectColumn(
            $this->events(forceFrom: false, ordered: true),
            "({$this->propertyName()})::numeric * ({$ratioSql})::numeric",
        );
    }

    /** Port of `grouped_last_event` (weighted-sum grouped recurring support). */
    public function groupedLastEvent(): array
    {
        $groups = $this->sanitizedGroupedBy();

        $order = implode(', ', [...$groups, 'events.timestamp DESC, created_at DESC']);
        $select = implode(', ', [
            'DISTINCT ON ('.implode(', ', $groups).') '.implode(', ', $groups),
            'events.timestamp',
            "({$this->propertyName()})::numeric AS value",
        ]);

        $scope = $this->events()->toBase()->selectRaw($select)->orderByRaw($order);

        $rows = $this->selectRows($scope);

        return $this->prepareGroupedResult($rows, timestamp: true);
    }

    // -- count ----------------------------------------------------------------

    public function count(): AggregationResult
    {
        return $this->buildAggregationResultFromValue($this->events()->count());
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedCount(?array $columns = null): array
    {
        $columns ??= $this->groupedBy;

        $groups = $this->sanitizedColumns($columns);
        $select = implode(', ', [$this->aliasedSelect($groups), 'COUNT(*) AS events_count']);
        $scope = $this->events()->toBase()->selectRaw($select)->groupByRaw(implode(', ', array_values($groups)));

        return $this->groupedResultsWithValueAsCount(
            $this->prepareGroupedResult($this->selectRows($scope), columns: $columns),
        );
    }

    // -- max / last -----------------------------------------------------------

    public function max(bool $withCount = true): AggregationResult
    {
        $scope = $this->events()->toBase()->selectRaw(
            "MAX(({$this->propertyName()})::numeric) AS value".($withCount ? ', COUNT(*) AS events_count' : '')
        );

        $row = $this->selectOneFromScope($scope);

        return new AggregationResult(
            value: ($row['value'] ?? null) ?? 0,
            eventsCount: $withCount ? (int) ($row['events_count'] ?? 0) : null,
        );
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedMax(?array $columns = null, bool $withCount = true): array
    {
        $columns ??= $this->groupedBy;
        $groups = $this->sanitizedColumns($columns);

        $select = implode(', ', [
            $this->aliasedSelect($groups),
            "MAX(({$this->propertyName()})::numeric)",
            $withCount ? 'COUNT(*)' : 'NULL',
        ]);
        $scope = $this->events()->toBase()->selectRaw($select)->groupByRaw(implode(', ', array_values($groups)));

        return $this->prepareGroupedAggregatedValues($this->selectRows($scope), columns: $columns);
    }

    public function last(bool $withCount = true): AggregationResult
    {
        $lastEvent = $this->newestFirstScope()->first();
        $value = $lastEvent === null
            ? null
            : ($lastEvent->properties[$this->aggregationProperty] ?? null);

        return new AggregationResult(
            value: $value === null ? null : (string) $value,
            eventsCount: $withCount ? $this->events()->count() : null,
        );
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedLast(?array $columns = null, bool $withCount = true): array
    {
        $columns ??= $this->groupedBy;
        $sanitizedColumns = array_values($this->sanitizedColumns($columns));
        $distinctOnColumns = ($this->groupedBy !== []) ? array_values($this->sanitizedColumns($this->groupedBy)) : [];

        $countSelect = $withCount
            ? ($distinctOnColumns === []
                ? 'COUNT(*) OVER ()'
                : 'COUNT(*) OVER (PARTITION BY '.implode(', ', $distinctOnColumns).')')
            : 'NULL';

        $scope = $this->events()->toBase();
        if ($distinctOnColumns === []) {
            $scope->selectRaw(implode(', ', [
                ...$sanitizedColumns,
                "({$this->propertyName()})::numeric AS value",
                "{$countSelect} AS events_count",
            ]))->orderByRaw('events.timestamp DESC, created_at DESC')->limit(1);
        } else {
            $order = implode(', ', [...$distinctOnColumns, 'events.timestamp DESC, created_at DESC']);
            $scope->selectRaw(implode(', ', [
                'DISTINCT ON ('.implode(', ', $distinctOnColumns).')',
                ...$sanitizedColumns,
                "({$this->propertyName()})::numeric AS value",
                "{$countSelect} AS events_count",
            ]))->orderByRaw($order);
        }

        return $this->prepareGroupedAggregatedValues($this->selectRows($scope), columns: $columns);
    }

    // -- sum ------------------------------------------------------------------

    public function sum(bool $withCount = true): AggregationResult
    {
        $scope = $this->events()->toBase()->selectRaw(
            "SUM(({$this->propertyName()})::numeric) AS value".($withCount ? ', COUNT(*) AS events_count' : '')
        );

        $row = $this->selectOneFromScope($scope);

        return new AggregationResult(
            value: ($row['value'] ?? null) ?? 0,
            eventsCount: $withCount ? (int) ($row['events_count'] ?? 0) : null,
        );
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedSum(?array $columns = null, bool $withCount = true): array
    {
        $columns ??= $this->groupedBy;
        $groups = $this->sanitizedColumns($columns);

        $select = implode(', ', [
            $this->aliasedSelect($groups),
            "SUM(({$this->propertyName()})::numeric)",
            $withCount ? 'COUNT(*)' : 'NULL',
        ]);
        $scope = $this->events()->toBase()->selectRaw($select)->groupByRaw(implode(', ', array_values($groups)));

        return $this->prepareGroupedAggregatedValues($this->selectRows($scope), columns: $columns);
    }

    public function sumPreciseTotalAmountCents(): string
    {
        $value = $this->events()->sum('precise_total_amount_cents');

        return (string) ($value ?? 0);
    }

    /** @return list<array{groups: array<string, mixed>, value: mixed}> */
    public function groupedSumPreciseTotalAmountCents(): array
    {
        $groups = $this->sanitizedGroupedBy();

        $select = implode(', ', [...$groups, 'SUM(events.precise_total_amount_cents) AS value']);
        $scope = $this->events()->toBase()->selectRaw($select)->groupByRaw(implode(', ', $groups));

        return $this->prepareGroupedResult($this->selectRows($scope));
    }

    /** Port of `prorated_sum(period_duration:, persisted_duration:)`. */
    public function proratedSum(int|string $periodDuration, int|string|null $persistedDuration = null): ProratedAggregationResult
    {
        $ratio = $persistedDuration !== null
            ? $this->floatRatio($persistedDuration, $periodDuration)
            : $this->durationRatioSql('events.timestamp', $this->toDatetime(), $periodDuration);

        $sql = implode(', ', [
            "SUM(({$this->propertyName()})::numeric * ({$ratio})::numeric) AS prorated_value",
            "SUM(({$this->propertyName()})::numeric) AS value",
            'COUNT(*) AS events_count',
        ]);

        $row = $this->selectOneFromScope($this->events()->toBase()->selectRaw($sql));

        return $this->buildProratedAggregationResult($row);
    }

    /** @return list<GroupedProratedAggregationResult> */
    public function groupedProratedSum(int|string $periodDuration, int|string|null $persistedDuration = null): array
    {
        $ratio = $persistedDuration !== null
            ? $this->floatRatio($persistedDuration, $periodDuration)
            : $this->durationRatioSql('events.timestamp', $this->toDatetime(), $periodDuration);

        $groups = $this->sanitizedGroupedBy();

        $sql = implode(', ', [
            ...$groups,
            "SUM(({$this->propertyName()})::numeric * ({$ratio})::numeric) AS prorated_value",
            "SUM(({$this->propertyName()})::numeric) AS value",
            'COUNT(*) AS events_count',
        ]);
        $scope = $this->events()->toBase()->selectRaw($sql)->groupByRaw(implode(', ', $groups));

        return $this->prepareGroupedProratedResult($this->selectRows($scope));
    }

    /** Port of `sum_date_breakdown`. */
    public function sumDateBreakdown(): array
    {
        $dateField = $this->dateInCustomerTimezoneSql('events.timestamp');

        $scope = $this->events()->toBase()
            ->selectRaw("DATE({$dateField}) AS date, SUM(({$this->propertyName()})::numeric) AS value")
            ->groupByRaw("DATE({$dateField})")
            ->orderByRaw("DATE({$dateField}) ASC");

        return array_map(
            fn (array $row) => ['date' => (string) $row['date'], 'value' => (string) $row['value']],
            $this->selectRows($scope),
        );
    }

    // -- unique count ---------------------------------------------------------

    public function uniqueCount(): AggregationResult
    {
        $query = new Postgres\UniqueCountQuery($this);

        $row = $this->selectOne($query->sql(), $query->bindings());

        return $this->buildAggregationResultFromValue($row['aggregation'] ?? 0);
    }

    public function proratedUniqueCount(): AggregationResult
    {
        $query = new Postgres\UniqueCountQuery($this);

        $row = $this->selectOne(
            $query->proratedSql($this->fromDatetime(), $this->toDatetime(), $this->customerTimezone()),
            $query->bindings(),
        );

        return $this->buildAggregationResultFromValue($row['aggregation'] ?? 0);
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedUniqueCount(?array $columns = null): array
    {
        // NOTE: Important to use a clone to avoid mutating the current store
        // (Rails: `dup`) to associate the columns.
        $duplicated = clone $this;
        $duplicated->setGroupedBy($columns ?? $this->groupedBy);

        $query = new Postgres\UniqueCountQuery($duplicated);

        $rows = $this->selectRowsBySql($query->groupedSql(), $query->bindings());

        return $this->groupedResultsWithValueAsCount(
            $this->prepareGroupedResult($rows, columns: $columns ?? $this->groupedBy),
        );
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedProratedUniqueCount(): array
    {
        $query = new Postgres\UniqueCountQuery($this);

        $rows = $this->selectRowsBySql(
            $query->groupedProratedSql($this->fromDatetime(), $this->toDatetime(), $this->customerTimezone()),
            $query->bindings(),
        );

        return $this->groupedResultsWithValueAsCount($this->prepareGroupedResult($rows));
    }

    /**
     * Port of `active_unique_property?` — did an earlier add of the same
     * unique property happen in an active (present and not removed) state?
     */
    public function activeUniqueProperty(Event $event): bool
    {
        $payload = json_encode([$this->aggregationProperty => $event->properties[$this->aggregationProperty] ?? null]);

        $previousEvent = $this->events()
            ->where('id', '!=', $event->id)
            ->whereRaw('events.properties @> '.$this->quote($payload === false ? '{}' : $payload))
            ->whereRaw('events.timestamp < '.$this->datetimeLiteral($event->timestamp))
            ->orderByDesc('timestamp')
            ->first();

        $operationType = $previousEvent?->properties['operation_type'] ?? null;

        return $previousEvent !== null && ($operationType === null || $operationType === 'add');
    }

    // -- weighted sum ---------------------------------------------------------

    public function weightedSum(string|int|float|null $initialValue = 0): WeightedAggregationResult
    {
        $query = new Postgres\WeightedSumQuery($this);

        $row = $this->selectOne(
            $query->sql($this->fromDatetime(), $this->toDatetimeCeiled(), $initialValue ?? 0),
            $query->bindings(),
        );

        return $this->buildWeightedAggregationResult(
            value: $row['aggregation'] ?? 0,
            variationWithInitial: $row['variation_with_initial'] ?? 0,
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
    public function groupedWeightedSum(?array $columns = null, array $initialValues = []): array
    {
        $columns ??= $this->groupedBy;

        $duplicated = clone $this;
        $duplicated->setGroupedBy($columns);

        $query = new Postgres\WeightedSumQuery($duplicated);

        $formattedInitialValues = $duplicated->formattedWeightedSumInitialValues($initialValues);
        if ($formattedInitialValues === []) {
            return [];
        }

        $rows = $this->selectRowsBySql(
            $query->groupedSql($this->fromDatetime(), $this->toDatetimeCeiled(), $formattedInitialValues),
            $query->bindings(),
        );

        return $this->prepareGroupedWeightedValues($rows, $formattedInitialValues, columns: $columns);
    }

    /**
     * Port of `formatted_weighted_sum_initial_values` — builds the list of
     * initial values for each group present in the period's events.
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

    // -- Distinct combinations (charge filters / billing period pre-filtering) -

    /**
     * Port of `distinct_codes_and_property_combinations` — the distinct
     * [code, properties, last_seen_at] combinations present in the period's
     * events; only the filter_keys dimensions are kept.
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
        $scope = Event::query()
            ->where('external_subscription_id', $this->billingContext->externalId())
            ->where('organization_id', $this->billingContext->organizationId())
            ->whereIn('code', $codes)
            ->whereRaw('events.timestamp <= '.$this->datetimeLiteral($this->applicableToDatetime()));

        if (! $includeAllHistory) {
            $from = $this->fromDatetime();
            if ($from !== null) {
                $scope->whereRaw('events.timestamp >= '.$this->datetimeLiteral($from, floorToMilliseconds: true));
            }
        }

        $filterKeysSql = $filterKeys === []
            ? 'ARRAY[]::text[]'
            : 'ARRAY['.implode(', ', array_map($this->quote(...), $filterKeys)).']::text[]';

        $selects = implode(', ', [
            'events.code AS code',
            "coalesce((
                SELECT jsonb_object_agg(props.key, props.value)
                FROM jsonb_each_text(events.properties) AS props(key, value)
                WHERE props.key = ANY({$filterKeysSql})
            ), '{}'::jsonb) AS combination",
            $withLastSeenAt ? 'MAX(events.created_at) AS last_seen_at' : 'NULL AS last_seen_at',
        ]);

        $rows = $this->selectRows($scope->toBase()->selectRaw($selects)->groupByRaw('code, combination'));

        return array_map(
            fn (array $row) => [
                'code' => $row['code'],
                'combination' => is_array($row['combination'])
                    ? $row['combination']
                    : json_decode((string) $row['combination'], true) ?? [],
                'last_seen_at' => $row['last_seen_at'] === null ? null : (string) $row['last_seen_at'],
            ],
            $rows,
        );
    }

    // -- SQL helpers (Rails: sanitized_property_name & co) ---------------------

    /** Port of `sanitized_property_name` — a sanitized SQL literal. */
    public function propertyName(?string $property = null): string
    {
        return 'events.properties->>'.$this->quote($property ?? $this->aggregationProperty);
    }

    /** Port of `presence_condition` — the property key must exist. */
    public function presenceCondition(): string
    {
        return 'jsonb_exists(events.properties::jsonb, '.$this->quote($this->aggregationProperty).')';
    }

    /** Port of `numeric_condition` — ensure the property value is numeric. */
    public function numericCondition(): string
    {
        return $this->propertyName()." ~ '^-?\\d+(\\.\\d+)?$'";
    }

    /** @return list<string> */
    public function sanitizedGroupedBy(): array
    {
        return array_values($this->sanitizedColumns($this->groupedBy));
    }

    /** Port of `operation_type_sql`. */
    public function operationTypeSql(): string
    {
        return "COALESCE(events.properties->>'operation_type', 'add')";
    }

    /** Port of `created_at_ordering_column`. */
    public function createdAtOrderingColumn(): string
    {
        return 'events.created_at';
    }

    /**
     * Port of `duration_ratio_sql` — pro-rata of the duration in days
     * between the datetimes over the duration of the billing period, dates
     * in the customer timezone.
     */
    public function durationRatioSql(string $from, mixed $to, int|string $duration): string
    {
        $fromInTimezone = $this->dateInCustomerTimezoneSql($from);
        $toInTimezone = $this->dateInCustomerTimezoneSql($this->datetimeLiteral($to));

        return "((DATE({$toInTimezone}) - DATE({$fromInTimezone}))::numeric + 1) / {$duration}::numeric";
    }

    /** Port of `Utils::Timezone.date_in_customer_timezone_sql`. */
    public function dateInCustomerTimezoneSql(string $value): string
    {
        return "({$value})::timestamptz AT TIME ZONE ".$this->quote($this->customerTimezone());
    }

    public function customerTimezone(): string
    {
        return (string) $this->customer()->applicableTimezone();
    }

    /** Sanitized SQL string literal (Rails: sanitize_sql_for_conditions / bindings). */
    public function quote(mixed $value): string
    {
        return (string) DB::connection()->getPdo()->quote((string) $value);
    }

    /**
     * UTC datetime literal. `$floorToMilliseconds` mirrors Rails'
     * `to_time.floor(3)` (from_datetime); otherwise full microsecond
     * precision is kept.
     */
    public function datetimeLiteral(mixed $datetime, bool $floorToMilliseconds = false): string
    {
        $carbon = \Carbon\Carbon::parse($datetime)->utc();

        if ($floorToMilliseconds) {
            $carbon->microsecond = (int) (floor($carbon->microsecond / 1000) * 1000);
        }

        return $this->quote($carbon->format('Y-m-d H:i:s').'.'.sprintf('%06d', $carbon->microsecond));
    }

    /** Port of `apply_to_boundary` — the pay-in-advance upper boundary. */
    protected function applyToBoundary(Builder $scope): Builder
    {
        $boundaryEvent = (($this->boundaries['max_timestamp'] ?? null) !== null) ? $this->event : null;

        if ($boundaryEvent !== null && $boundaryEvent->id !== null) {
            $to = $this->datetimeLiteral($this->applicableToDatetime());
            $scope->whereRaw(
                "(events.timestamp < {$to} OR (events.timestamp = {$to} AND (events.created_at, events.id) <= "
                .'(SELECT boundary_event.created_at, boundary_event.id FROM events boundary_event WHERE boundary_event.id = '
                .$this->quote($boundaryEvent->id).')))'
            );

            return $scope;
        }

        $scope->whereRaw('events.timestamp <= '.$this->datetimeLiteral($this->applicableToDatetime()));

        return $scope;
    }

    /** Port of `filters_scope` — the charge-filter property filters. */
    protected function filtersScope(Builder $scope): Builder
    {
        foreach ($this->matchingFilters as $key => $values) {
            $list = implode(', ', array_map($this->quote(...), array_map(strval(...), $values)));
            $scope->whereRaw('events.properties ->> '.$this->quote((string) $key)." IN ({$list})");
        }

        $conditions = [];
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
                $clauses[] = '(coalesce(events.properties ->> '.$this->quote((string) $key).", '') IN ({$list}))";
            }

            $clause = implode(' AND ', $clauses);
            if ($clause !== '') {
                $conditions[] = "({$clause})";
            }
        }

        if ($conditions !== []) {
            $scope->whereRaw('NOT ('.implode(' OR ', $conditions).')');
        }

        return $scope;
    }

    /** Port of `apply_grouped_by_values`. */
    protected function applyGroupedByValues(Builder $scope): Builder
    {
        foreach ($this->groupedByValues as $groupedBy => $groupedByValue) {
            if ($groupedByValue !== null && $groupedByValue !== '') {
                $payload = json_encode([(string) $groupedBy => (string) $groupedByValue]);
                $scope->whereRaw('events.properties @> '.$this->quote($payload === false ? '{}' : $payload));
            } else {
                $scope->whereRaw(
                    'COALESCE(events.properties->>'.$this->quote((string) $groupedBy).", '') = ''"
                );
            }
        }

        return $scope;
    }

    // -- Row preparation (Rails: prepare_grouped_result & co) -------------------

    /**
     * Port of `prepare_grouped_result` — values for each group:
     * [{ groups: {...}, value: ... }, ...].
     *
     * @return list<array{groups: array<string, mixed>, value: mixed, timestamp?: mixed}>
     */
    protected function prepareGroupedResult(array $rows, bool $timestamp = false, ?array $columns = null): array
    {
        $columns ??= $this->groupedBy;

        return array_map(function (array $row) use ($timestamp, $columns) {
            $values = array_values($row);
            $groupCount = count($values) - ($timestamp ? 2 : 1);

            $result = [
                'groups' => $this->buildGroups(array_slice($values, 0, $groupCount), columns: $columns),
                'value' => $values[count($values) - 1],
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
     * (including the initial value) and the rows count (including the two
     * boundary rows).
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
                value: $values[count($values) - 3],
                variationWithInitial: $values[count($values) - 2] ?? 0,
                rowsCount: $values[count($values) - 1],
                initialValues: $initialValues,
            );
        }, $rows);
    }

    /** @return array<string, string> alias => sanitized expression */
    protected function sanitizedColumns(array $columns): array
    {
        $sql = [];
        foreach (array_values($columns) as $index => $column) {
            $sql["g{$index}"] = $this->propertyName($column);
        }

        return $sql;
    }

    /**
     * The group expressions aliased for the SELECT clause (so the prepared
     * rows carry g0..gN keys); GROUP BY uses the plain expressions.
     *
     * @param  array<string, string>  $groups  alias => expression
     */
    protected function aliasedSelect(array $groups): string
    {
        $fragments = [];
        foreach ($groups as $alias => $expr) {
            $fragments[] = "{$expr} AS {$alias}";
        }

        return implode(', ', $fragments);
    }

    // -- Execution helpers ------------------------------------------------------

    /**
     * @param  Builder<Event>  $scope
     * @return list<string> the single column's values
     */
    protected function selectColumn(Builder $scope, string $expression): array
    {
        $rows = $this->selectRows($scope->toBase()->selectRaw($expression.' AS field_value'));

        return array_map(fn (array $row) => $row['field_value'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function selectOneFromScope(\Illuminate\Database\Query\Builder $scope): ?array
    {
        return $this->selectOne($scope->toSql(), $scope->getBindings());
    }

    /** @return array<string, mixed>|null */
    protected function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = DB::selectOne($sql, $bindings);

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function selectRows(\Illuminate\Database\Query\Builder $scope): array
    {
        return $this->selectRowsBySql($scope->toSql(), $scope->getBindings());
    }

    /** @return list<array<string, mixed>> */
    protected function selectRowsBySql(string $sql, array $bindings = []): array
    {
        return array_map(
            fn (object $row) => (array) $row,
            DB::select($sql, $bindings),
        );
    }

    /**
     * Rails' `events.order(timestamp: :desc, created_at: :desc)` (the ASC
     * ordering from `ordered: true` is structurally deduplicated away).
     */
    protected function newestFirstScope(): Builder
    {
        return $this->events()->orderByDesc('timestamp')->orderByDesc('created_at');
    }

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
        $ratio = (float) $persistedDuration / (float) $periodDuration;

        return json_encode($ratio);
    }
}
