<?php

declare(strict_types=1);

namespace App\Services\ContractRateCards;

use Carbon\CarbonImmutable;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\ContractRateCard;
use App\Services\Billing\RateCards\Schedule;

/**
 * Port of Rails' ContractRateCards::AdvanceBillingClockService
 * (app/services/contract_rate_cards/advance_billing_clock_service.rb) —
 * moves a card's billing clock to the next instant its schedule falls due.
 *
 * Exhausted, or never set, or later: what it must never do is move back.
 */
class AdvanceBillingClockService extends BaseService
{
    public function __construct(
        private readonly ContractRateCard $contractRateCard,
        private readonly Schedule $schedule,
        private readonly CarbonImmutable $timestamp,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();

        $advanceTo = $this->schedule->nextBillingAt($this->timestamp);
        $clock = $this->contractRateCard->next_billing_at;

        if ($advanceTo === null || $clock === null || $advanceTo->gt(CarbonImmutable::parse($clock))) {
            $this->contractRateCard->next_billing_at = $advanceTo;
            $this->contractRateCard->save();
        }

        return $result;
    }
}
