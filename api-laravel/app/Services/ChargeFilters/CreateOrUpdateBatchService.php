<?php

declare(strict_types=1);

namespace App\Services\ChargeFilters;

use App\Models\Charge;
use Illuminate\Support\Str;
use App\Models\ChargeFilter;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ChargeFilterValue;
use Illuminate\Support\Facades\DB;

use function array_key_exists;

/**
 * Port of Rails' ChargeFilters::CreateOrUpdateBatchService
 * (app/services/charge_filters/create_or_update_batch_service.rb) — the
 * nested filters write used by Charges::Create/UpdateService.
 *
 * Not ported:
 * - TODO(port): PaperTrail request disabling around the order-keeping `touch`
 *   (versions table writes are a later milestone).
 */
class CreateOrUpdateBatchService extends BaseService
{
    public function __construct(
        private readonly Charge $charge,
        private readonly array $filtersParams,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('filters');
        $result->filters = [];

        if ($this->filtersParams === []) {
            DB::transaction(function (): void {
                foreach ($this->charge->filters()->get() as $filter) {
                    $this->removeFilter($filter);
                }
            });

            return $result;
        }

        if ($this->emptyFilterValues()) {
            return $result->singleValidationFailure('value_is_mandatory', 'values');
        }

        // Codes are read before the insert, so two concurrent requests
        // creating filters with the same values on one charge can pick the
        // same one. The index is what rejects it; retrying recomputes against
        // the row that won, rather than failing a request nobody got wrong.
        $attempted = false;

        while (true) {
            try {
                DB::transaction(function () use ($result): void {
                    $this->createOrUpdateFilters($result);
                });

                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempted) {
                    throw $e;
                }

                $attempted = true;
            }
        }

        return $result;
    }

    /**
     * Everything an attempt accumulates is built here rather than in the
     * constructor, so a retry starts clean without a second list of things
     * to undo.
     *
     * @param  array<string, mixed>  $row
     */
    private function createOrUpdateFilters(BaseResult $result): void
    {
        /** @var list<array<string, mixed>> $newFilterRows */
        $newFilterRows = [];
        /** @var list<array<string, mixed>> $newFilterValueRows */
        $newFilterValueRows = [];
        /** @var list<ChargeFilter> $resultFilters */
        $resultFilters = [];
        $takenFilterCodes = null;

        $filtersByValuesKey = [];

        foreach ($this->charge->filters()->with('values.billableMetricFilter')->get() as $filter) {
            $filtersByValuesKey[$this->valuesKey($filter->toH())] = $filter;
        }

        $billableMetricFiltersByKey = [];

        // Rails: charge.billable_metric.filters (BillableMetricFilter relation
        // on BillableMetric — not ported there yet, queried directly).
        $metricFilters = \App\Models\BillableMetricFilter::query()
            ->where('billable_metric_id', $this->charge->billable_metric_id)
            ->orderBy('key')
            ->get();

        foreach ($metricFilters as $metricFilter) {
            $billableMetricFiltersByKey[$metricFilter->key] = $metricFilter;
        }

        foreach ($this->filtersParams as $filterParam) {
            $filterParam = (array) $filterParam;
            $valuesParams = array_map(
                fn ($values) => (array) $values,
                (array) ($filterParam['values'] ?? []),
            );

            // NOTE: since a filter could be a refinement of another one, we
            //       have to make sure that we are targeting the right one.
            $existingFilter = $filtersByValuesKey[$this->valuesKey($valuesParams)] ?? null;

            $propertiesParam = $filterParam['properties'] ?? null;
            if (is_array($propertiesParam)) {
                unset($propertiesParam['presentation_group_keys']);
            }

            $properties = \App\Services\ChargeModels\FilterPropertiesService::call(
                chargeable: $this->charge,
                properties: $propertiesParam,
            )->properties;

            if ($existingFilter !== null) {
                $this->updateExistingFilter(
                    $existingFilter,
                    $filterParam,
                    $valuesParams,
                    $properties,
                    $billableMetricFiltersByKey,
                    $resultFilters,
                );

                continue;
            }

            // NOTE: pre-generate the UUID so we can wire ChargeFilterValue
            //       rows to their parent without a round-trip after the bulk
            //       insert.
            $filterId = (string) Str::uuid();

            // NOTE: build an in-memory model instance only to run validations.
            $filterInstance = new ChargeFilter([
                'charge_id' => $this->charge->id,
                'organization_id' => $this->charge->organization_id,
                'invoice_display_name' => $filterParam['invoice_display_name'] ?? null,
                'properties' => $properties,
            ]);
            $filterInstance->id = $filterId;

            $this->validateOrFail($filterInstance);

            if ($takenFilterCodes === null) {
                $takenFilterCodes = $this->charge->filters()
                    ->withoutGlobalScope('keptOrdered')
                    ->pluck('code')
                    ->filter()
                    ->all();
            }

            // NOTE: insert (bulk) skips callbacks, so the code the model
            //       would assign is built here.
            $code = ChargeFilter::nextFreeCode(
                ChargeFilter::generateCode($valuesParams),
                $takenFilterCodes,
            );
            $takenFilterCodes[] = $code;

            $newFilterRows[] = [
                'id' => $filterId,
                'charge_id' => $this->charge->id,
                'organization_id' => $this->charge->organization_id,
                'invoice_display_name' => $filterParam['invoice_display_name'] ?? null,
                'properties' => json_encode($properties),
                'code' => $code,
            ];

            $valuesParams = array_map(
                fn ($values) => array_values((array) $values),
                $valuesParams,
            );

            foreach ($valuesParams as $key => $values) {
                $billableMetricFilter = $billableMetricFiltersByKey[$key] ?? null;

                $valueInstance = new ChargeFilterValue([
                    'charge_filter_id' => $filterId,
                    'billable_metric_filter_id' => $billableMetricFilter?->id,
                    'organization_id' => $this->charge->organization_id,
                    'values' => $values,
                ]);

                $this->validateOrFail($valueInstance);

                // The bulk insert bypasses Eloquent casts — take the raw
                // Postgres array literal the cast produced.
                $newFilterValueRows[] = [
                    'charge_filter_id' => $filterId,
                    'billable_metric_filter_id' => $billableMetricFilter?->id,
                    'organization_id' => $this->charge->organization_id,
                    'values' => $valueInstance->getAttributes()['values'],
                ];
            }
        }

        $now = now();
        $timestampRows = [];

        // NOTE: a single bulk insert stamps every row with the same
        //       created_at/updated_at. The model's default scope orders by
        //       updated_at, so we assign monotonically-increasing per-row
        //       offsets to preserve input order.
        foreach ($newFilterRows as $idx => $row) {
            $timestampRows[] = $now->copy()->addMicroseconds($idx)->format('Y-m-d H:i:s.u');
        }

        foreach ($newFilterRows as $idx => &$row) {
            $row['created_at'] = $timestampRows[$idx];
            $row['updated_at'] = $timestampRows[$idx];
        }
        unset($row);

        foreach ($newFilterRows as $idx => $row) {
            DB::table('charge_filters')->insert($row);

            $resultFilters[] = ChargeFilter::query()->withoutGlobalScopes()->findOrFail($row['id']);
        }

        $valueTimestamps = [];

        foreach ($newFilterValueRows as $idx => $row) {
            $valueTimestamps[] = $now->copy()->addMicroseconds($idx)->format('Y-m-d H:i:s.u');
        }

        foreach ($newFilterValueRows as $idx => &$row) {
            $row['created_at'] = $valueTimestamps[$idx];
            $row['updated_at'] = $valueTimestamps[$idx];
        }
        unset($row);

        foreach ($newFilterValueRows as $row) {
            DB::table('charge_filter_values')->insert($row);
        }

        // NOTE: remove old filters that were not created or updated.
        $keptIds = array_map(fn (ChargeFilter $filter) => $filter->id, $resultFilters);

        $this->charge->filters()
            ->withoutGlobalScope('keptOrdered')
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->get()
            ->each(fn (ChargeFilter $filter) => $this->removeFilter($filter));

        $result->filters = $resultFilters;
    }

    /**
     * @param  array<string, mixed>  $filterParam
     * @param  array<string, list<string>>  $valuesParams
     * @param  array<string, \App\Models\BillableMetricFilter>  $billableMetricFiltersByKey
     * @param  list<ChargeFilter>  $resultFilters
     */
    private function updateExistingFilter(
        ChargeFilter $filter,
        array $filterParam,
        array $valuesParams,
        array $properties,
        array $billableMetricFiltersByKey,
        array &$resultFilters,
    ): void {
        $filter->charge_id = $this->charge->id;
        $filter->organization_id = $this->charge->organization_id;
        $filter->invoice_display_name = $filterParam['invoice_display_name'] ?? null;
        $filter->properties = $properties;

        $filter->save();

        // NOTE: Make sure updated_at is touched even if not changed to keep
        //       the right order.
        $filter->touch();

        $filterValuesIndexed = [];

        foreach ($filter->values()->get() as $filterValue) {
            $filterValuesIndexed[$filterValue->billable_metric_filter_id] = $filterValue;
        }

        foreach ($valuesParams as $key => $values) {
            $values = array_values((array) $values);
            $billableMetricFilter = $billableMetricFiltersByKey[$key] ?? null;

            $filterValue = $filterValuesIndexed[$billableMetricFilter?->id] ?? null;
            $filterValue ??= $filter->values()->make();

            $filterValue->charge_filter_id = $filter->id;
            $filterValue->billable_metric_filter_id = $billableMetricFilter?->id;
            $filterValue->organization_id = $this->charge->organization_id;
            $filterValue->values = $values;

            $filterValue->save();

            // NOTE: Make sure updated_at is touched even if not changed to
            //       keep the right order.
            $filterValue->touch();
        }

        $resultFilters[] = $filter;
    }

    /**
     * @param  array<string, list<string>>  $valuesParams
     */
    private function valuesKey(array $valuesParams): string
    {
        $normalized = $valuesParams;

        foreach ($normalized as &$values) {
            $values = (array) $values;
            sort($values);
        }
        unset($values);

        ksort($normalized);

        return json_encode($normalized);
    }

    private function removeFilter(ChargeFilter $filter): void
    {
        ChargeFilterValue::query()
            ->where('charge_filter_id', $filter->id)
            ->update(['deleted_at' => now()]);

        $filter->delete(); // discard (deleted_at)
    }

    private function emptyFilterValues(): bool
    {
        foreach ($this->filtersParams as $filterParam) {
            $filterParam = (array) $filterParam;

            if (! array_key_exists('values', $filterParam) || $filterParam['values'] === null || $filterParam['values'] === []) {
                return true;
            }
        }

        return false;
    }

    private function validateOrFail(ChargeFilter|ChargeFilterValue $instance): void
    {
        $errors = $instance->validateAttributes();

        if ($errors !== []) {
            // Rails: `validate!` -> ActiveRecord::RecordInvalid, rescued by the
            // calling service into record_validation_failure!.
            static::makeResult()
                ->recordValidationFailure($errors)
                ->raiseIfError();
        }
    }
}
