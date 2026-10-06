<?php

declare(strict_types=1);

namespace App\Services\ChargeFilters;

use App\Models\ChargeFilter;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ChargeFilterValue;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' ChargeFilters::DestroyService
 * (app/services/charge_filters/destroy_service.rb) — the standalone (per-id)
 * filter destroy used by the GraphQL DestroyChargeFilter mutation and the
 * subscription filter overrides.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): ChargeFilters::FilterCascadable#trigger_filter_cascade —
 *   ChargeFilters::CascadeJob/CascadeService are a later milestone; the
 *   cascade_updates flag is accepted and skipped (same convention as the
 *   Charges::DestroyService port).
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?ChargeFilter $chargeFilter,
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

        $chargeFilter = $this->chargeFilter;

        // Rails: capture values before the transaction discards them — to_h
        // uses the kept scope and would return an empty hash after discard.
        $filterValues = $chargeFilter->toH();

        DB::transaction(function () use ($chargeFilter): void {
            ChargeFilterValue::query()
                ->where('charge_filter_id', $chargeFilter->id)
                ->update(['deleted_at' => now()]);

            $chargeFilter->delete();
        });

        // TODO(port): trigger_filter_cascade(action: "destroy", ...).

        $result->charge_filter = $chargeFilter;

        return $result;
    }
}
