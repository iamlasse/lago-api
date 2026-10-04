<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Contracts::MaterializeRateCardsService
 * (app/services/contracts/materialize_rate_cards_service.rb) — copies the
 * plan's rate cards onto the contract: one contract_rate_card per
 * plan_rate_card, carrying the billing lifecycle (anchor, clock, units) and
 * a copy of the entry's phase timeline.
 */
class MaterializeRateCardsService extends BaseService
{
    public function __construct(
        private readonly Contract $contract,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('contract_rate_cards');

        if ($this->contract->catalogPlan === null) {
            return $result;
        }

        try {
            $materialized = DB::transaction(function (): array {
                $materialized = [];

                // Locked so a concurrent phase edit waits for this contract
                // to commit and then hits plan_locked, instead of landing a
                // phase the copy missed.
                $planRateCards = $this->contract->catalogPlan->appliedRateCards()
                    ->lockForUpdate()
                    ->with(['ratePhases.rateOverride'])
                    ->get();

                foreach ($planRateCards as $planRateCard) {
                    $lifecycle = $this->contract->defaultRateCardLifecycle();

                    $card = $this->contract->appliedRateCards()->create([
                        'organization_id' => $this->contract->organization_id,
                        'rate_card_id' => $planRateCard->rate_card_id,
                        'units' => $planRateCard->units,
                        'effective_date' => $lifecycle['effective_date'],
                        'billing_anchor_date' => $lifecycle['billing_anchor_date'],
                        'next_billing_at' => $lifecycle['next_billing_at'],
                    ]);

                    $this->copyRatePhases($planRateCard, $card);

                    $materialized[] = $card;
                }

                return $materialized;
            });

            $result->contract_rate_cards = $materialized;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    private function copyRatePhases($planRateCard, $card): void
    {
        foreach ($planRateCard->ratePhases as $phase) {
            $overrideId = null;

            if ($phase->rateOverride !== null) {
                // A phase owns its override (unique index), so the copy gets
                // its own row.
                $copy = $phase->rateOverride->replicate();
                $copy->save();

                $overrideId = $copy->id;
            }

            $card->ratePhases()->create([
                'organization_id' => $this->contract->organization_id,
                'code' => $phase->code,
                'position' => $phase->position,
                'name' => $phase->name,
                'billing_interval_cycle_count' => $phase->billing_interval_cycle_count,
                'rate_override_id' => $overrideId,
            ]);
        }
    }
}
