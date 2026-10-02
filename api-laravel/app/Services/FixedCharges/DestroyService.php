<?php

declare(strict_types=1);

namespace App\Services\FixedCharges;

use App\Models\FixedCharge;
use App\Services\BaseResult;

/**
 * Port of Rails' FixedCharges::DestroyService
 * (app/services/fixed_charges/destroy_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): FixedCharges::DestroyChildrenJob cascade dispatch.
 */
class DestroyService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?FixedCharge $fixedCharge,
        private readonly bool $cascadeUpdates = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fixed_charge');

        if ($this->fixedCharge === null) {
            return $result->notFoundFailure('fixed_charge');
        }

        $fixedCharge = $this->fixedCharge;

        try {
            if ($fixedCharge->trashed()) {
                // Rails: Discard::RecordNotDiscarded (already discarded).
                return $result->serviceFailure(
                    'fixed_charge_already_deleted',
                    'Failed to discard the record due to a previous discard',
                );
            }

            $fixedCharge->delete(); // discard (deleted_at)
        } catch (\Illuminate\Database\QueryException $e) {
            // Rails: rescue Discard::RecordNotDiscarded.
            return $result->serviceFailure('fixed_charge_already_deleted', $e->getMessage(), $e);
        }

        $result->fixed_charge = $fixedCharge;

        return $result;
    }
}
