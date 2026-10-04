<?php

declare(strict_types=1);

namespace App\Services\PlanRateCards;

use App\Models\PlanRateCard;
use App\Models\RateOverride;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' PlanRateCards::DestroyService
 * (app/services/plan_rate_cards/destroy_service.rb) — removes a rate card
 * from a plan. A plan with contracts is immutable: pricing changes go
 * through a new plan and a contract migration.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?PlanRateCard $planRateCard,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plan_rate_card');
        $planRateCard = $this->planRateCard;

        try {
            if ($planRateCard === null) {
                return $result->notFoundFailure('applied_rate_card');
            }

            if ($planRateCard->catalogPlan->attachedToContracts()) {
                return $result->singleValidationFailure('plan_locked', 'plan');
            }

            DB::transaction(function () use ($planRateCard): void {
                $phases = $planRateCard->ratePhases()->get();

                RateOverride::query()
                    ->whereIn('id', $phases->pluck('rate_override_id')->filter()->values())
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);

                $planRateCard->ratePhases()->whereNull('deleted_at')->update(['deleted_at' => now()]);

                $planRateCard->delete();
            });

            $result->plan_rate_card = $planRateCard;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
