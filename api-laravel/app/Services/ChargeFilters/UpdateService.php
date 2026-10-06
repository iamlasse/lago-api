<?php

declare(strict_types=1);

namespace App\Services\ChargeFilters;

use App\Models\ChargeFilter;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' ChargeFilters::UpdateService
 * (app/services/charge_filters/update_service.rb) — the standalone (per-id)
 * filter update used by the GraphQL UpdateChargeFilter mutation.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): ChargeFilters::FilterCascadable#trigger_filter_cascade —
 *   ChargeFilters::CascadeJob/CascadeService are a later milestone; the
 *   cascade_updates flag is accepted and skipped (same convention as the
 *   Charges::UpdateService port).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?ChargeFilter $chargeFilter,
        private readonly array $params,
        private readonly bool $cascadeUpdates = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('charge_filter');

        if ($this->chargeFilter === null) {
            return $result->notFoundFailure('charge_filter');
        }

        $oldProperties = is_array($this->chargeFilter->properties)
            ? $this->chargeFilter->properties
            : (array) $this->chargeFilter->properties;

        try {
            $chargeFilter = DB::transaction(function () use ($result): ChargeFilter {
                $chargeFilter = $this->chargeFilter;

                if (array_key_exists('invoice_display_name', $this->params)) {
                    $chargeFilter->invoice_display_name = $this->params['invoice_display_name'];
                }

                if (array_key_exists('properties', $this->params)) {
                    $propertiesParam = $this->params['properties'];
                    if (is_array($propertiesParam)) {
                        unset($propertiesParam['presentation_group_keys']);
                    }

                    $chargeFilter->properties = \App\Services\ChargeModels\FilterPropertiesService::call(
                        chargeable: $chargeFilter->charge,
                        properties: $propertiesParam,
                    )->properties;
                }

                $errors = $chargeFilter->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $chargeFilter->save();

                return $chargeFilter;
            });
        } catch (FailedResult $e) {
            // Rails: rescue ActiveRecord::RecordInvalid →
            // result.record_validation_failure!.
            return $this->embedFailure($result, $e);
        }

        // TODO(port): trigger_filter_cascade(action: "update", ...) — the
        // old/new properties and invoice display name would cascade onto the
        // child plans' matching filters.

        $result->charge_filter = $chargeFilter;

        return $result;
    }
}
