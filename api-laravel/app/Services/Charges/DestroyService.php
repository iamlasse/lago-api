<?php

declare(strict_types=1);

namespace App\Services\Charges;

use App\Models\Charge;
use App\Models\ChargeFilter;
use App\Services\BaseResult;
use App\Models\ChargeFilterValue;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Charges::DestroyService (app/services/charges/destroy_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Charges::DestroyChildrenJob cascade dispatch — `cascade_updates`
 *   is accepted but no-op (parent-plan children are a later milestone).
 */
class DestroyService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Charge $charge,
        private readonly bool $cascadeUpdates = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('charge');

        if ($this->charge === null) {
            return $result->notFoundFailure('charge');
        }

        $charge = $this->charge;

        DB::transaction(function () use ($charge): void {
            $charge->delete(); // discard (deleted_at)

            $deletedAt = now();

            $filterIds = $charge->filters()->pluck('id');

            ChargeFilterValue::query()
                ->whereIn('charge_filter_id', $filterIds)
                ->update(['deleted_at' => $deletedAt]);

            ChargeFilter::query()->where('charge_id', $charge->id)
                ->update(['deleted_at' => $deletedAt]);
        });

        $result->charge = $charge;

        // TODO(port): Charges::DestroyChildrenJob — cascade to child plans.

        return $result;
    }
}
