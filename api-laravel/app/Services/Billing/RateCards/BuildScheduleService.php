<?php

declare(strict_types=1);

namespace App\Services\Billing\RateCards;

use Carbon\CarbonImmutable;
use App\Services\BaseResult;
use App\Services\BaseService;
use InvalidArgumentException;
use App\Services\Billing\Phase;
use App\Services\Billing\Terms;
use App\Models\ContractRateCard;

/**
 * Port of Rails' Billing::RateCards::BuildScheduleService (app/services/
 * billing/rate_cards/build_schedule_service.rb) — assembles a card's
 * Schedule: its rate timeline, terms, phase queue, resume point and window.
 */
class BuildScheduleService extends BaseService
{
    public function __construct(
        private readonly ContractRateCard $contractRateCard,
        private readonly ?CarbonImmutable $endsAt = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('schedule');

        try {
            $rateCard = $this->contractRateCard->rateCard;
            $rates = $rateCard->orderedRates()->get()->all();

            if ($rates === []) {
                $result->notFoundFailure(resource: 'rate');

                return $result;
            }

            $terms = Terms::from(
                timing: $rateCard->billing_timing,
                prorated: $rateCard->proration(),
            );

            $timezone = $this->timezone();

            $result->schedule = new Schedule(
                rates: $rates,
                terms: $terms,
                phases: $this->phases(),
                startsAt: CarbonImmutable::parse($this->contractRateCard->effective_date)
                    ->setTimezone($timezone),
                endsAt: $this->scheduleEndsAt(),
                anchorDate: CarbonImmutable::parse($this->contractRateCard->billing_anchor_date)
                    ->setTimezone($timezone),
                timezone: $timezone,
                resumeAt: $this->resumeAt(),
            );

            return $result;
        } catch (InvalidArgumentException $error) {
            $result->serviceFailure(
                code: 'invalid_billing_schedule',
                message: $error->getMessage(),
                error: $error,
            );

            return $result;
        }
    }

    /** Rails: `#timezone` — the customer's applicable timezone. */
    private function timezone(): string
    {
        return $this->contractRateCard->contract->customer->applicableTimezone();
    }

    /** Rails: `#schedule_ends_at` — the earlier of the caller's cap and the contract's end. */
    private function scheduleEndsAt(): ?CarbonImmutable
    {
        $contractEndsAt = $this->contractRateCard->contract->ended_at;

        if ($this->endsAt === null && $contractEndsAt === null) {
            return null;
        }

        if ($this->endsAt === null) {
            return CarbonImmutable::parse($contractEndsAt);
        }

        if ($contractEndsAt === null) {
            return $this->endsAt;
        }

        return CarbonImmutable::min($this->endsAt, CarbonImmutable::parse($contractEndsAt));
    }

    /** Rails: `#resume_at` — the last stored cycle start, if any. */
    private function resumeAt(): ?CarbonImmutable
    {
        $max = $this->contractRateCard->billingSegments()->max('cycle_started_at');

        return $max !== null ? CarbonImmutable::parse($max) : null;
    }

    /**
     * Rails: `#phases` — the configured queue, closed by a default phase
     * unless the last one already runs to the end of the card.
     *
     * @return list<Phase>
     */
    private function phases(): array
    {
        $configured = [];
        foreach ($this->contractRateCard->ratePhases as $ratePhase) {
            $configured[] = new Phase(
                code: $ratePhase->code,
                billingIntervalCycleCount: $ratePhase->billing_interval_cycle_count,
                rateOverride: $ratePhase->rateOverride,
            );
        }

        $last = end($configured);

        if ($last === false || ! $last->unbounded()) {
            $configured[] = Phase::default();
        }

        return $configured;
    }
}
