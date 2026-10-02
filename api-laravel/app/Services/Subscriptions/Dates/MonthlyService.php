<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\Dates;

use App\Services\Subscriptions\DatesService;
use Carbon\CarbonImmutable;

/**
 * Port of Rails' Subscriptions::Dates::MonthlyService.
 */
class MonthlyService extends DatesService
{
    public function computeFromDate(?CarbonImmutable $date = null): CarbonImmutable
    {
        $date ??= $this->baseDate();

        if ($this->plan->pay_in_advance || $this->terminatedPayInArrears()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfMonth();
        }

        return $this->subscription->anniversary()
            ? $this->previousAnniversaryDay($date)
            : $date->startOfMonth();
    }

    protected function computeChargesFromDate(): CarbonImmutable
    {
        // NOTE: when subscription is terminated, we must bill on the current period
        if ($this->terminated()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfMonth();
        }

        if ($this->plan->payInArrears()) {
            return $this->computeFromDate();
        }

        return $this->subscription->calendar()
            ? $this->baseDate()->startOfMonth()
            : $this->previousAnniversaryDay($this->baseDate());
    }

    protected function computeChargesToDate(): CarbonImmutable
    {
        return $this->subscription->calendar()
            ? $this->computeChargesFromDate()->endOfMonth()->startOfDay()
            : $this->computeToDateFrom($this->computeChargesFromDate());
    }

    /**
     * NOTE: `from_date` is not necessarily the beginning of the period: on a
     * subscription resulting from an upgrade, it is clamped to `started_at`
     * while the anniversary is inherited from the previous subscription. The
     * duration is the one of the whole period.
     */
    protected function computeDuration(CarbonImmutable $fromDate): int
    {
        $periodStart = $this->computePreviousBeginningOfPeriod($fromDate->startOfDay());

        return (int) $periodStart->diffInDays($this->computeToDateFrom($periodStart)->addDay());
    }

    protected function computeFixedChargesFromDate(): CarbonImmutable
    {
        return $this->computeChargesFromDate();
    }

    protected function computeFixedChargesToDate(): CarbonImmutable
    {
        return $this->computeChargesToDate();
    }

    protected function computeBaseDate(): CarbonImmutable
    {
        $billingDate = $this->billingDate();

        // NOTE: if subscription anniversary is on last day of month and current
        // month days count is less than month anniversary day count, we need to
        // use the last day of the previous month
        if ($this->subscription->anniversary() && $this->lastDayOfMonth($billingDate) && ($billingDate->day < $this->subscriptionAt()->day)) {
            $previousMonth = $this->subMonthsWithClamp($billingDate, 1);

            if ($previousMonth->endOfMonth()->day >= $this->subscriptionAt()->day) {
                return $previousMonth->setDay($this->subscriptionAt()->day);
            }

            return $previousMonth->endOfMonth()->startOfDay();
        }

        return $this->subMonthsWithClamp($billingDate, 1);
    }

    protected function computeToDate(): CarbonImmutable
    {
        return $this->computeToDateFrom($this->computeFromDate());
    }

    protected function computeToDateFrom(CarbonImmutable $fromDate): CarbonImmutable
    {
        if ($this->subscription->calendar() || $this->subscriptionAt()->day === 1) {
            return $fromDate->endOfMonth()->startOfDay();
        }

        $year = $fromDate->year;
        $month = $fromDate->month + 1;
        $day = $this->subscriptionAt()->day - 1;

        if ($month > 12) {
            $month = 1;
            $year++;
        }

        $date = $this->buildDate($year, $month, $day);

        // NOTE: if subscription anniversary day is higher than the current last
        // day of the month, subscription period will end on the previous end of day
        if ($this->lastDayOfMonth($date) && $this->subscriptionAt()->day > $date->day) {
            return $date->subDay()->startOfDay();
        }

        return $date;
    }

    protected function computeNextEndOfPeriod(): CarbonImmutable
    {
        if ($this->subscription->calendar()) {
            return $this->billingDate()->endOfMonth()->startOfDay();
        }

        $billingDate = $this->billingDate();
        $year = $billingDate->year;
        $month = $billingDate->month;
        $day = $this->subscriptionAt()->day;

        // NOTE: we need the last day of the period, and not the first of the next one
        $resultDate = $this->buildDate($year, $month, $day)->subDay()->startOfDay();
        if ($resultDate->gte($billingDate)) {
            return $resultDate;
        }

        $month++;
        if ($month > 12) {
            $month = 1;
            $year++;
        }

        return $this->buildDate($year, $month, $day)->subDay()->startOfDay();
    }

    protected function computePreviousBeginningOfPeriod(CarbonImmutable $date): CarbonImmutable
    {
        return $this->subscription->calendar()
            ? $date->startOfMonth()
            : $this->previousAnniversaryDay($date);
    }

    protected function previousAnniversaryDay(CarbonImmutable $date): CarbonImmutable
    {
        $subscriptionDay = $this->subscriptionAt()->day;

        // NOTE: if subscription anniversary day is higher than the current last
        // day of the month, anniversary day is on the current day
        if ($this->subscription->anniversary() && $this->lastDayOfMonth($date) && ($date->day < $subscriptionDay)) {
            $day = $date->day;
        } else {
            $day = $subscriptionDay;
        }

        if ($date->day < $day) {
            $year = ($date->month === 1) ? $date->year - 1 : $date->year;
            $month = ($date->month === 1) ? 12 : $date->month - 1;
        } else {
            $year = $date->year;
            $month = $date->month;
        }

        return $this->buildDate($year, $month, $day);
    }
}
