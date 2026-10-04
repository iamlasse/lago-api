<?php

declare(strict_types=1);

namespace App\Services\RatePhases;

use App\Models\RatePhase;
use App\Models\PlanRateCard;
use App\Models\RateOverride;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ContractRateCard;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' RatePhases::ReplaceService
 * (app/services/rate_phases/replace_service.rb) — replaces an applied rate
 * card's whole phase timeline in one write.
 */
class ReplaceService extends BaseService
{
    public function __construct(
        private readonly ?PlanRateCard $planRateCard = null,
        private readonly ?ContractRateCard $contractRateCard = null,
        private readonly array $phasesParams = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('rate_phases');

        try {
            $appliedRateCard = $this->planRateCard ?? $this->contractRateCard;

            if ($appliedRateCard === null) {
                return $result->notFoundFailure('applied_rate_card');
            }

            $sequenceFailure = $this->validateSequence($result);
            if ($sequenceFailure !== null) {
                return $sequenceFailure;
            }

            return DB::transaction(function () use ($result, $appliedRateCard): BaseResult {
                // The lock covers the edit check too, so a contract
                // activating (or a plan gaining a subscription) concurrently
                // cannot slip past the guard mid-replace.
                $locked = $appliedRateCard::query()->whereKey($appliedRateCard->id)->lockForUpdate()->first();

                $blocked = $locked->editErrorCode();
                if ($blocked !== null) {
                    return $result->singleValidationFailure($blocked, 'rate_phases');
                }

                $this->discardExistingPhases($locked);

                $created = [];

                foreach ($this->orderedParams() as $phase) {
                    $rateOverrideId = null;
                    if (($phase['rate_override'] ?? null) !== null) {
                        $rateOverrideId = \App\Services\RateOverrides\CreateService::call(
                            rateCard: $locked->rateCard,
                            params: $phase['rate_override'],
                        )->raiseIfError()->rate_override->id;
                    }

                    $ratePhase = new RatePhase([
                        'organization_id' => $locked->organization_id,
                        'plan_rate_card_id' => $this->planRateCard?->id,
                        'contract_rate_card_id' => $this->contractRateCard?->id,
                        'code' => $phase['code'] ?? null,
                        'position' => $phase['position'],
                        'name' => $phase['name'] ?? null,
                        'billing_interval_cycle_count' => $phase['billing_interval_cycle_count'] ?? null,
                        'rate_override_id' => $rateOverrideId,
                    ]);

                    $errors = $ratePhase->validateAttributes();
                    if ($errors !== []) {
                        $result->recordValidationFailure($errors)->raiseIfError();
                    }

                    $ratePhase->save();
                    $created[] = $ratePhase;
                }

                $result->rate_phases = $created;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    private function discardExistingPhases($appliedRateCard): void
    {
        $existingPhases = $appliedRateCard->ratePhases()->get();

        RateOverride::query()
            ->whereIn('id', $existingPhases->pluck('rate_override_id')->filter()->values())
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        $appliedRateCard->ratePhases()->whereNull('deleted_at')->update(['deleted_at' => now()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderedParams(): array
    {
        $phases = $this->phasesParams;
        usort($phases, fn (array $a, array $b): int => (RatePhase::parsePosition($a['position'] ?? null) ?? 0)
            <=> (RatePhase::parsePosition($b['position'] ?? null) ?? 0));

        return $phases;
    }

    /** @return BaseResult|null null when the sequence is valid. */
    private function validateSequence(BaseResult $result): ?BaseResult
    {
        if ($this->phasesParams === []) {
            return $result->singleValidationFailure('value_is_mandatory', 'rate_phases');
        }

        $positions = array_map(
            fn (array $phase): ?int => RatePhase::parsePosition($phase['position'] ?? null),
            $this->orderedParams(),
        );

        if ($positions !== range(1, count($this->phasesParams))) {
            return $result->singleValidationFailure('positions_must_be_contiguous', 'rate_phases');
        }

        // The timeline ends with exactly one indefinite phase (null
        // billing_interval_cycle_count): none before the last, and the last
        // is one.
        $ordered = $this->orderedParams();

        foreach (array_slice($ordered, 0, -1) as $phase) {
            if (($phase['billing_interval_cycle_count'] ?? null) === null || $phase['billing_interval_cycle_count'] === '') {
                return $result->singleValidationFailure('indefinite_phase_must_be_last', 'rate_phases');
            }
        }

        $last = end($ordered);
        if (($last['billing_interval_cycle_count'] ?? null) !== null && $last['billing_interval_cycle_count'] !== '') {
            return $result->singleValidationFailure('last_phase_must_be_indefinite', 'rate_phases');
        }

        return null;
    }
}
