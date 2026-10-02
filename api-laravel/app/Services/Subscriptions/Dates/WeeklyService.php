<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\Dates;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use App\Services\Subscriptions\DatesService;

/**
 * Port of Rails' Subscriptions::Dates::WeeklyService.
 */
class WeeklyService extends DatesService
{
    public const WEEK_DURATION = 7;

    /** Ruby wday numbering (0 = Sunday). */
    protected static function weekdayNumber(string $dayName): int
    {
        return match ($dayName) {
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            default => throw new InvalidArgumentException("Unknown weekday {$dayName}"),
        };
    }

    protected function computeBaseDate(): CarbonImmutable
    {
        return $this->billingDate()->subWeek();
    }

    protected function computeFromDate(): CarbonImmutable
    {
        if ($this->plan->pay_in_advance || $this->terminatedPayInArrears()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfWeek();
        }

        return $this->subscription->anniversary()
            ? $this->previousAnniversaryDay($this->baseDate())
            : $this->baseDate()->startOfWeek();
    }

    protected function computeToDate(): CarbonImmutable
    {
        return $this->computeToDateFrom($this->computeFromDate());
    }

    protected function computeToDateFrom(CarbonImmutable $fromDate): CarbonImmutable
    {
        if ($this->subscription->calendar()) {
            return $fromDate->startOfWeek()->addWeek()->subDay()->startOfDay(); // end_of_week
        }

        return $fromDate->addDays(6);
    }

    protected function computeChargesFromDate(): CarbonImmutable
    {
        // NOTE: when subscription is terminated, we must bill on the current period
        if ($this->terminated()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfWeek();
        }

        if ($this->plan->payInArrears()) {
            return $this->computeFromDate();
        }

        return $this->subscription->calendar()
            ? $this->baseDate()->startOfWeek()
            : $this->previousAnniversaryDay($this->baseDate());
    }

    protected function computeChargesToDate(): CarbonImmutable
    {
        return $this->subscription->calendar()
            ? $this->computeChargesFromDate()->startOfWeek()->addWeek()->subDay()->startOfDay()
            : $this->computeChargesFromDate()->addDays(6);
    }

    protected function computeNextEndOfPeriod(): CarbonImmutable
    {
        if ($this->subscription->calendar()) {
            return $this->billingDate()->startOfWeek()->addWeek()->subDay()->startOfDay();
        }

        $billingDate = $this->billingDate();
        $previousDayWday = $this->subscriptionAt()->subDay()->dayOfWeek;

        if ($billingDate->dayOfWeek === $previousDayWday) {
            return $billingDate;
        }

        // NOTE: we need the last day of the period, and not the first of the next one
        return $this->nextOccurring($billingDate, $this->subscriptionDayName())->subDay()->startOfDay();
    }

    protected function computePreviousBeginningOfPeriod(CarbonImmutable $date): CarbonImmutable
    {
        return $this->subscription->calendar()
            ? $date->startOfWeek()
            : $this->previousAnniversaryDay($date);
    }

    /** Rails: `previous_anniversary_day(date)` — previous occurrence of the subscription weekday. */
    protected function previousAnniversaryDay(CarbonImmutable $date): CarbonImmutable
    {
        if ($date->dayOfWeek === $this->subscriptionAt()->dayOfWeek) {
            return $date;
        }

        return $this->previousOccurring($date, $this->subscriptionDayName());
    }

    /** e.g. :monday — the subscription_at weekday name. */
    protected function subscriptionDayName(): string
    {
        return mb_strtolower($this->subscriptionAt()->format('l'));
    }

    protected function computeDuration(CarbonImmutable $fromDate): int
    {
        return self::WEEK_DURATION;
    }

    protected function computeChargesDuration(CarbonImmutable $fromDate): int
    {
        return self::WEEK_DURATION;
    }

    protected function computeFixedChargesFromDate(): CarbonImmutable
    {
        return $this->computeChargesFromDate();
    }

    protected function computeFixedChargesToDate(): CarbonImmutable
    {
        return $this->computeChargesToDate();
    }

    protected function computeFixedChargesDuration(CarbonImmutable $fromDate): int
    {
        return self::WEEK_DURATION;
    }

    // -- Weekday occurrence helpers (Ruby Date#next_occurring / #prev_occurring) ----

    protected function nextOccurring(CarbonImmutable $date, string $dayName): CarbonImmutable
    {
        $target = self::weekdayNumber($dayName);
        $diff = ($target - $date->dayOfWeek + 7) % 7;
        $diff = $diff === 0 ? 7 : $diff;

        return $date->addDays($diff)->startOfDay();
    }

    protected function previousOccurring(CarbonImmutable $date, string $dayName): CarbonImmutable
    {
        $target = self::weekdayNumber($dayName);
        $diff = ($date->dayOfWeek - $target + 7) % 7;
        $diff = $diff === 0 ? 7 : $diff;

        return $date->subDays($diff)->startOfDay();
    }
}
