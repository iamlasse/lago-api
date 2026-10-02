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
    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

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

        // Rails' uuid attribute type casts non-uuid strings to nil before the
        // query (so "unknown" simply matches nothing); here an invalid uuid
        // would make Postgres raise, so only valid uuids reach the query —
        // the count comparison below still uses the full id list and yields
        // the not-found failure for invalid ids.
        $queryableIds = array_values(array_filter(
            $metricIds,
            fn ($id): bool => is_string($id) && preg_match(self::UUID_REGEX, $id) === 1,
        ));

        $count = $queryableIds === [] ? 0 : BillableMetric::query()
            ->where('organization_id', $this->organization->id)
            ->whereIn('id', $queryableIds)
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
            // Same uuid-cast guard as validateBillableMetrics.
            $queryableIds = array_values(array_filter(
                $addOnIds,
                fn ($id): bool => is_string($id) && preg_match(self::UUID_REGEX, $id) === 1,
            ));

            $count = $queryableIds === [] ? 0 : AddOn::query()
                ->where('organization_id', $this->organization->id)
                ->whereIn('id', $queryableIds)
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
