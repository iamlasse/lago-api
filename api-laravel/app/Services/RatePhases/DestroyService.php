<?php

declare(strict_types=1);

namespace App\Services\RatePhases;

use App\Models\RatePhase;
use App\Models\RateOverride;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' RatePhases::DestroyService
 * (app/services/rate_phases/destroy_service.rb) — removes a single phase;
 * later phases shift up. The indefinite tail is pinned: removing it would
 * give the timeline an end.
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?RatePhase $ratePhase,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_phase');
        $ratePhase = $this->ratePhase;

        try {
            if ($ratePhase === null) {
                return $result->notFoundFailure('rate_phase');
            }

            return DB::transaction(function () use ($result, $ratePhase): BaseResult {
                // Rails: applied_rate_card.with_lock — sequence reads and
                // renumbering run under the card's lock.
                $appliedRateCard = $ratePhase->planRateCard ?? $ratePhase->contractRateCard;

                if ($appliedRateCard === null) {
                    return $result->notFoundFailure('rate_phaseable');
                }

                $appliedRateCard::query()->whereKey($appliedRateCard->id)->lockForUpdate()->first();

                $blocked = $appliedRateCard->editErrorCode();
                if ($blocked !== null) {
                    return $result->singleValidationFailure($blocked, 'rate_phase');
                }

                // The phase was loaded before the lock; a concurrent delete
                // may have discarded it.
                $ratePhase->refresh();

                if ($ratePhase->trashed()) {
                    return $result->notFoundFailure('rate_phase');
                }

                $siblings = $appliedRateCard->ratePhases()->orderBy('position')->get();

                if ($siblings->last()?->id === $ratePhase->id && $ratePhase->billing_interval_cycle_count === null) {
                    return $result->singleValidationFailure('indefinite_phase_not_deletable', 'rate_phase');
                }

                if ($siblings->count() === 1) {
                    return $result->singleValidationFailure('cannot_delete_last_phase', 'rate_phase');
                }

                $rateOverrideId = $ratePhase->rate_override_id;

                $ratePhase->delete();

                if ($rateOverrideId !== null) {
                    RateOverride::query()->whereKey($rateOverrideId)->whereNull('deleted_at')->update(['deleted_at' => now()]);
                }

                $siblings->filter(fn ($phase): bool => $phase->position > $ratePhase->position)
                    ->each(function ($phase): void {
                        $phase->position = $phase->position - 1;
                        $phase->save();
                    });

                $result->rate_phase = $ratePhase;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
