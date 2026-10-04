<?php

declare(strict_types=1);

namespace App\Services\ContractRateCards;

use App\Models\RateOverride;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ContractRateCard;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' ContractRateCards::DestroyService
 * (app/services/contract_rate_cards/destroy_service.rb) — removes a rate
 * card from a contract. Authoring is pending-only: once the contract is
 * active its cards are signed.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?ContractRateCard $contractRateCard,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('contract_rate_card');
        $contractRateCard = $this->contractRateCard;

        try {
            if ($contractRateCard === null) {
                return $result->notFoundFailure('applied_rate_card');
            }

            if (! $contractRateCard->contract->editable()) {
                return $result->singleValidationFailure('contract_locked', 'contract');
            }

            DB::transaction(function () use ($contractRateCard): void {
                $phases = $contractRateCard->ratePhases()->get();

                RateOverride::query()
                    ->whereIn('id', $phases->pluck('rate_override_id')->filter()->values())
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);

                $contractRateCard->ratePhases()->whereNull('deleted_at')->update(['deleted_at' => now()]);

                $contractRateCard->delete();
            });

            $result->contract_rate_card = $contractRateCard;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
