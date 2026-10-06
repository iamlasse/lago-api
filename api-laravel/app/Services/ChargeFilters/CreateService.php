<?php

declare(strict_types=1);

namespace App\Services\ChargeFilters;

use App\Models\Charge;
use App\Models\ChargeFilter;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ChargeFilterValue;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' ChargeFilters::CreateService
 * (app/services/charge_filters/create_service.rb) — the standalone (per-id)
 * filter create used by the GraphQL CreateChargeFilter mutation and the
 * subscription filter overrides.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): ChargeFilters::FilterCascadable#trigger_filter_cascade —
 *   ChargeFilters::CascadeJob/CascadeService are a later milestone; the
 *   cascade_updates flag is accepted and skipped (same convention as the
 *   Charges::Create/UpdateService ports).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Charge $charge,
        private readonly array $params,
        private readonly bool $cascadeUpdates = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('charge_filter');

        if ($this->charge === null) {
            return $result->notFoundFailure('charge');
        }

        if (($this->params['values'] ?? null) === null || (array) $this->params['values'] === []) {
            return $result->singleValidationFailure('value_is_mandatory', 'values');
        }

        try {
            $chargeFilter = DB::transaction(function () use ($result): ChargeFilter {
                $charge = $this->charge;

                // Rails: filtered_properties — the model's default properties
                // minus the presentation_group_keys control key.
                $propertiesParam = $this->params['properties'] ?? null;
                if (is_array($propertiesParam)) {
                    unset($propertiesParam['presentation_group_keys']);
                }

                $properties = \App\Services\ChargeModels\FilterPropertiesService::call(
                    chargeable: $charge,
                    properties: $propertiesParam,
                )->properties;

                $chargeFilter = new ChargeFilter([
                    'organization_id' => $charge->organization_id,
                    'invoice_display_name' => $this->params['invoice_display_name'] ?? null,
                    'properties' => $properties,
                ]);
                $chargeFilter->charge_id = $charge->id;

                $errors = $chargeFilter->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $chargeFilter->save();

                $this->createFilterValues($charge, $chargeFilter, $result);

                $chargeFilter->assignCode();

                return $chargeFilter;
            });
        } catch (FailedResult $e) {
            // Rails: rescue ActiveRecord::RecordInvalid →
            // result.record_validation_failure!.
            return $this->embedFailure($result, $e);
        }

        $result->charge_filter = $chargeFilter;

        return $result;
    }

    /**
     * Rails: create_filter_values — each entry is {key => [values]}; the
     * billable metric filter is matched by key (nil when unknown).
     */
    private function createFilterValues(Charge $charge, ChargeFilter $chargeFilter, BaseResult $result): void
    {
        foreach ((array) ($this->params['values'] ?? []) as $key => $values) {
            $billableMetricFilter = \App\Models\BillableMetricFilter::query()
                ->where('billable_metric_id', $charge->billable_metric_id)
                ->where('key', $key)
                ->first();

            $filterValue = new ChargeFilterValue([
                'billable_metric_filter_id' => $billableMetricFilter?->id,
                'organization_id' => $charge->organization_id,
            ]);
            $filterValue->charge_filter_id = $chargeFilter->id;
            $filterValue->values = array_values((array) $values);

            $errors = $filterValue->validateAttributes();
            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $filterValue->save();
        }
    }
}
