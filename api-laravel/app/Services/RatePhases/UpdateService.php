<?php

declare(strict_types=1);

namespace App\Services\RatePhases;

use App\Models\RatePhase;
use App\Models\RateOverride;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' RatePhases::UpdateService
 * (app/services/rate_phases/update_service.rb) — updates a single phase,
 * addressed by its code. A new position moves the phase within the
 * sequence; the indefinite tail stays pinned last.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?RatePhase $ratePhase,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_phase');
        $ratePhase = $this->ratePhase;
        $params = $this->params;

        try {
            if ($ratePhase === null) {
                return $result->notFoundFailure('rate_phase');
            }

            // REST can send "" where null is meant; normalize before the
            // terminal check or the blank slips past it and persists as an
            // indefinite phase.
            if (array_key_exists('billing_interval_cycle_count', $params)
                && ($params['billing_interval_cycle_count'] ?? null) === '') {
                $params['billing_interval_cycle_count'] = null;
            }

            return DB::transaction(function () use ($result, $ratePhase, $params): BaseResult {
                $appliedRateCard = $ratePhase->planRateCard ?? $ratePhase->contractRateCard;

                if ($appliedRateCard === null) {
                    return $result->notFoundFailure('rate_phaseable');
                }

                $appliedRateCard::query()->whereKey($appliedRateCard->id)->lockForUpdate()->first();

                $blocked = $appliedRateCard->editErrorCode();
                if ($blocked !== null) {
                    return $result->singleValidationFailure($blocked, 'rate_phase');
                }

                // The phase was loaded before the lock; a concurrent move may
                // have renumbered it, and the shift below trusts its
                // position. A concurrent delete leaves it discarded.
                $ratePhase->refresh();

                if ($ratePhase->trashed()) {
                    return $result->notFoundFailure('rate_phase');
                }

                $siblings = $appliedRateCard->ratePhases()->orderBy('position')->get();
                $isLast = $siblings->last()?->id === $ratePhase->id;

                // Duration checks come first: a tail swap through one update
                // (moving a phase last while making it indefinite) is not a
                // thing — the tail is pinned.
                if (array_key_exists('billing_interval_cycle_count', $params)) {
                    if ($params['billing_interval_cycle_count'] === null && ! $isLast) {
                        return $result->singleValidationFailure('indefinite_phase_must_be_last', 'billing_interval_cycle_count');
                    }

                    if ($params['billing_interval_cycle_count'] !== null && $isLast) {
                        return $result->singleValidationFailure('last_phase_must_be_indefinite', 'billing_interval_cycle_count');
                    }
                }

                if (array_key_exists('position', $params)) {
                    $target = RatePhase::parsePosition($params['position']);

                    $failure = $this->positionFailure($result, $target, $siblings, $isLast);
                    if ($failure !== null) {
                        return $failure;
                    }

                    $this->moveTo($ratePhase, $target, $siblings);
                }

                if (array_key_exists('name', $params)) {
                    $ratePhase->name = $params['name'];
                }
                if (array_key_exists('code', $params)) {
                    $ratePhase->code = $params['code'];
                }
                if (array_key_exists('billing_interval_cycle_count', $params)) {
                    $ratePhase->billing_interval_cycle_count = $params['billing_interval_cycle_count'];
                }

                $supersededOverrideId = null;
                if (array_key_exists('rate_override', $params)) {
                    $supersededOverrideId = $ratePhase->rate_override_id;

                    if ($params['rate_override'] === null) {
                        $ratePhase->rate_override_id = null;
                    } else {
                        $override = \App\Services\RateOverrides\CreateService::call(
                            rateCard: $appliedRateCard->rateCard,
                            params: $params['rate_override'],
                        )->raiseIfError()->rate_override;

                        $ratePhase->rate_override_id = $override->id;
                    }
                }

                $errors = $ratePhase->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $ratePhase->save();

                // A provided rate_override replaces the phase's override;
                // null clears it. The replaced override is discarded once the
                // phase has moved off it.
                if ($supersededOverrideId !== null && $supersededOverrideId !== $ratePhase->rate_override_id) {
                    RateOverride::query()->whereKey($supersededOverrideId)->whereNull('deleted_at')->update(['deleted_at' => now()]);
                }

                $result->rate_phase = $ratePhase;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, RatePhase>  $siblings
     */
    private function positionFailure(BaseResult $result, ?int $target, $siblings, bool $isLast): ?BaseResult
    {
        if ($target === null || $target < 1 || $target > $siblings->count()) {
            return $result->singleValidationFailure('positions_must_be_contiguous', 'position');
        }

        if ($isLast && $target !== $siblings->count()) {
            return $result->singleValidationFailure('indefinite_phase_must_be_last', 'position');
        }

        if (! $isLast && $target === $siblings->count()) {
            return $result->singleValidationFailure('last_phase_must_be_indefinite', 'position');
        }

        return null;
    }

    /**
     * The phase parks on the free slot past the end while the others shift,
     * so the unique (card, position) index never sees two phases on one slot.
     *
     * @param  \Illuminate\Support\Collection<int, RatePhase>  $siblings
     */
    private function moveTo(RatePhase $ratePhase, ?int $target, $siblings): void
    {
        $current = $ratePhase->position;
        if ($target === $current) {
            return;
        }

        $maxPosition = $siblings->max('position');
        $ratePhase->position = $maxPosition + 1;
        $ratePhase->save();

        if ($target < $current) {
            $siblings
                ->filter(fn ($phase): bool => $phase->position >= $target && $phase->position <= $current - 1)
                ->sortByDesc('position')
                ->each(function ($phase): void {
                    $phase->position = $phase->position + 1;
                    $phase->save();
                });
        } else {
            $siblings
                ->filter(fn ($phase): bool => $phase->position >= $current + 1 && $phase->position <= $target)
                ->sortBy('position')
                ->each(function ($phase): void {
                    $phase->position = $phase->position - 1;
                    $phase->save();
                });
        }

        $ratePhase->position = $target;
        $ratePhase->save();
    }
}
