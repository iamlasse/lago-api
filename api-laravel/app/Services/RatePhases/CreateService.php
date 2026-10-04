<?php

declare(strict_types=1);

namespace App\Services\RatePhases;

use App\Models\RatePhase;
use App\Models\PlanRateCard;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ContractRateCard;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' RatePhases::CreateService
 * (app/services/rate_phases/create_service.rb) — inserts a phase into its
 * applied rate card's sequence. Positions renumber (later phases shift
 * down); the phase's code is the stable identifier.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?PlanRateCard $planRateCard = null,
        private readonly ?ContractRateCard $contractRateCard = null,
        private readonly array $params = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_phase');
        $params = $this->params;

        try {
            $appliedRateCard = $this->planRateCard ?? $this->contractRateCard;

            if ($appliedRateCard === null) {
                return $result->notFoundFailure('rate_phaseable');
            }

            // REST can send "" where null is meant; normalize before the
            // sequence checks or the blank slips past them and persists as
            // an indefinite phase.
            if (array_key_exists('billing_interval_cycle_count', $params)
                && ($params['billing_interval_cycle_count'] ?? null) === '') {
                $params['billing_interval_cycle_count'] = null;
            }

            return DB::transaction(function () use ($result, $appliedRateCard, $params): BaseResult {
                // Rails: applied_rate_card.with_lock — the sequence is read,
                // validated and renumbered under the card's lock, and the
                // edit guard runs inside so a contract activating (or a plan
                // gaining a subscription) concurrently cannot slip past it.
                $locked = $appliedRateCard::query()->whereKey($appliedRateCard->id)->lockForUpdate()->first();

                $blocked = $locked->editErrorCode();
                if ($blocked !== null) {
                    return $result->singleValidationFailure($blocked, 'rate_phase');
                }

                $existing = $locked->ratePhases()->orderBy('position')->get();

                $position = ($params['position'] ?? null) !== null && $params['position'] !== ''
                    ? RatePhase::parsePosition($params['position'])
                    : $this->defaultPosition($existing, $params);

                if ($position === null || $position < 1 || $position > $existing->count() + 1) {
                    return $result->singleValidationFailure('positions_must_be_contiguous', 'position');
                }

                // Validate the prospective sequence before touching anything:
                // the indefinite phase (null cycle count) is the last one,
                // and only it.
                $counts = $existing->pluck('billing_interval_cycle_count')->all();
                array_splice($counts, $position - 1, 0, [$params['billing_interval_cycle_count'] ?? null]);

                $nonTail = array_slice($counts, 0, -1);
                if (in_array(null, $nonTail, true)) {
                    return $result->singleValidationFailure('indefinite_phase_must_be_last', 'billing_interval_cycle_count');
                }

                if (end($counts) !== null) {
                    return $result->singleValidationFailure('last_phase_must_be_indefinite', 'billing_interval_cycle_count');
                }

                // Highest positions first so the unique (card, position)
                // index never sees a duplicate mid-shift.
                $existing->filter(fn ($phase): bool => $phase->position >= $position)
                    ->sortByDesc('position')
                    ->each(function ($phase): void {
                        $phase->position = $phase->position + 1;
                        $phase->save();
                    });

                $rateOverrideId = $this->buildOverride($appliedRateCard, $params);

                $ratePhase = new RatePhase([
                    'organization_id' => $locked->organization_id,
                    'plan_rate_card_id' => $this->planRateCard?->id,
                    'contract_rate_card_id' => $this->contractRateCard?->id,
                    'code' => ($params['code'] ?? null) !== '' ? ($params['code'] ?? null) : null,
                    'position' => $position,
                    'billing_interval_cycle_count' => $params['billing_interval_cycle_count'] ?? null,
                    'name' => $params['name'] ?? null,
                    'rate_override_id' => $rateOverrideId,
                ]);

                $errors = $ratePhase->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $ratePhase->save();

                $result->rate_phase = $ratePhase;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `default_position` — an omitted position appends at the end,
     * except a definite phase lands just before an indefinite tail, which
     * must stay terminal.
     */
    private function defaultPosition($existing, array $params): ?int
    {
        $last = $existing->last();

        if ($last !== null && $last->billing_interval_cycle_count === null
            && ($params['billing_interval_cycle_count'] ?? null) !== null) {
            return $last->position;
        }

        return $existing->count() + 1;
    }

    private function buildOverride($appliedRateCard, array $params): ?string
    {
        if (! array_key_exists('rate_override', $params) || $params['rate_override'] === null) {
            return null;
        }

        $override = \App\Services\RateOverrides\CreateService::call(
            rateCard: $appliedRateCard->rateCard,
            params: $params['rate_override'],
        )->raiseIfError()->rate_override;

        return $override->id;
    }
}
