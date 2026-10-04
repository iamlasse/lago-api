<?php

declare(strict_types=1);

namespace App\Services\AddOns;

use App\Models\AddOn;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' AddOns::DestroyService
 * (app/services/add_ons/destroy_service.rb) — the add-on is discarded (soft
 * delete) and its fixed charges are soft-deleted with it.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?AddOn $addOn,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('add_on');

        if ($this->addOn === null) {
            return $result->notFoundFailure('add_on');
        }

        return $this->rescueFailures(function () use ($result): BaseResult {
            DB::transaction(function (): void {
                $this->addOn->delete();

                // rubocop Rails/SkipsModelValidations — Rails updates the
                // fixed charges' deleted_at in bulk; the port keeps the bulk
                // update.
                $this->addOn->fixedCharges()->update(['deleted_at' => now()]);
            });

            $result->add_on = $this->addOn;

            return $result;
        }, $result);
    }
}
