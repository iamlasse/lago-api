<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\AddOn;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Models\BillableMetric;

/**
 * Port of Rails' Plans::ChargeablesValidationService
 * (app/services/plans/chargeables_validation_service.rb) — verifies the
 * billable metrics / add-ons referenced by the nested charges exist in the
 * organization before the transaction starts.
 */
class ChargeablesValidationService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly ?array $charges = null,
        private readonly ?array $fixedCharges = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        if (! $this->shouldValidate()) {
            return $result;
        }

        $this->validateBillableMetrics($result);
        $this->validateAddOns($result);

        return $result;
    }

    private function shouldValidate(): bool
    {
        return ($this->charges !== null && $this->charges !== [])
            || ($this->fixedCharges !== null && $this->fixedCharges !== []);
    }

    private function validateBillableMetrics(BaseResult $result): void
    {
        if ($this->charges === null || $this->charges === []) {
            return;
        }

        $metricIds = array_values(array_filter(array_unique(array_map(
            fn ($charge) => is_array($charge) ? ($charge['billable_metric_id'] ?? null) : null,
            $this->charges,
        ))));

        if ($metricIds === []) {
            return;
        }

        $count = BillableMetric::query()
            ->where('organization_id', $this->organization->id)
            ->whereIn('id', $metricIds)
            ->count();

        if ($count !== count($metricIds)) {
            $result->notFoundFailure('billable_metrics');
        }
    }

    private function validateAddOns(BaseResult $result): void
    {
        if ($this->fixedCharges === null || $this->fixedCharges === []) {
            return;
        }

        $addOnIds = array_values(array_filter(array_unique(array_map(
            fn ($charge) => is_array($charge) ? ($charge['add_on_id'] ?? null) : null,
            $this->fixedCharges,
        ))));

        if ($addOnIds !== []) {
            $count = AddOn::query()
                ->where('organization_id', $this->organization->id)
                ->whereIn('id', $addOnIds)
                ->count();

            if ($count !== count($addOnIds)) {
                $result->notFoundFailure('add_ons');
            }
        }

        $addOnCodes = array_values(array_filter(array_unique(array_map(
            fn ($charge) => is_array($charge) ? ($charge['add_on_code'] ?? null) : null,
            $this->fixedCharges,
        ))));

        if ($addOnCodes !== []) {
            $count = AddOn::query()
                ->where('organization_id', $this->organization->id)
                ->whereIn('code', $addOnCodes)
                ->count();

            if ($count !== count($addOnCodes)) {
                $result->notFoundFailure('add_ons');
            }
        }
    }
}
