<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\Dates;

use Carbon\CarbonImmutable;
use App\Services\Subscriptions\DatesService;

/**
 * Port of Rails' Subscriptions::Dates::YearlyService.
 */
class YearlyService extends DatesService
{
    public function firstMonthInYearlyPeriod(): bool
    {
        if ($this->subscription->calendar()) {
            return $this->billingDate()->month === 1;
        }

        return $this->monthlyService()->computeFromDate($this->billingDate())->month === $this->subscriptionAt()->month;
    }

    public function firstMonthInFirstYearlyPeriod(): bool
    {
        if ($this->subscription->calendar()) {
            $billingDate = $this->billingDate();

            return $billingDate->month === 1 && $billingDate->year === $this->subscriptionAt()->year;
        }

        $billingFromDate = $this->monthlyService()->computeFromDate($this->billingDate());

        return $billingFromDate->month === $this->subscriptionAt()->month
            && $billingFromDate->year === $this->subscriptionAt()->year;
    }

    // -- Boundary fill gating (charges/fixed charges billed monthly or not) ----------

    protected function shouldFillChargesBoundaries(): bool
    {
        if ($this->currentUsage) {
            return true;
        }

        if ($this->plan->bill_charges_monthly) {
            return true;
        }

        if ($this->plan->bill_fixed_charges_monthly) {
            return $this->firstMonthInYearlyPeriod();
        }

        return true;
    }

    protected function shouldFillFixedChargesBoundaries(): bool
    {
        if ($this->plan->bill_fixed_charges_monthly) {
            return true;
        }

        if ($this->plan->bill_charges_monthly) {
            return $this->firstMonthInYearlyPeriod();
        }

        return true;
    }

    protected function computeBaseDate(): CarbonImmutable
    {
        $billingDate = $this->billingDate();

        // NOTE: if subscription anniversary is on last day of month and current
        // month days count is less than month anniversary day count, we need to
        // use the last day of the previous month
        if ($this->subscription->anniversary() && $this->lastDayOfMonth($billingDate) && ($billingDate->day < $this->subscriptionAt()->day)) {
            $previousYear = $this->subYearsWithClamp($billingDate, 1);

            if ($previousYear->endOfMonth()->day >= $this->subscriptionAt()->day) {
                return $previousYear->endOfMonth()->startOfDay()->setDay($this->subscriptionAt()->day);
            }

            return $previousYear->endOfMonth()->startOfDay();
        }

        return $this->subYearsWithClamp($billingDate, 1);
    }

    protected function monthlyService(): MonthlyService
    {
        return new MonthlyService($this->subscription, $this->billingDate(), $this->currentUsage, $this->billingDate());
    }

    protected function computeFromDate(): CarbonImmutable
    {
        if ($this->plan->pay_in_advance || $this->terminatedPayInArrears()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfYear();
        }

        return $this->subscription->anniversary()
            ? $this->previousAnniversaryDay($this->baseDate())
            : $this->baseDate()->startOfYear();
    }

    protected function computeToDate(): CarbonImmutable
    {
        return $this->computeToDateFrom($this->computeFromDate());
    }

    protected function computeToDateFrom(CarbonImmutable $fromDate): CarbonImmutable
    {
        if ($this->subscription->calendar() || $this->subscriptionAt()->dayOfYear === 1) {
            return $fromDate->endOfYear()->startOfDay();
        }

        $year = $fromDate->year + 1;
        $month = $fromDate->month;
        $day = $this->subscriptionAt()->day - 1;

        $date = $this->buildDate($year, $month, $day);

        // NOTE: if subscription anniversary day is higher than the current last
        // day of the month, subscription period will end on the previous end of day
        if ($this->lastDayOfMonth($date) && $this->subscriptionAt()->day > $date->day) {
            return $date->subDay()->startOfDay();
        }

        return $date;
    }

    protected function computeChargesFromDate(): CarbonImmutable
    {
        if ($this->plan->bill_charges_monthly) {
            return $this->monthlyService()->computeChargesFromDate();
        }

        if ($this->terminated()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfYear();
        }

        if ($this->plan->payInArrears()) {
            return $this->computeFromDate();
        }

        return $this->subscription->calendar()
            ? $this->baseDate()->startOfYear()
            : $this->previousAnniversaryDay($this->baseDate());
    }

    protected function computeChargesToDate(): CarbonImmutable
    {
        if ($this->plan->bill_charges_monthly) {
            return $this->monthlyService()->computeChargesToDate();
        }

        return $this->subscription->calendar()
            ? $this->computeChargesFromDate()->endOfYear()->startOfDay()
            : $this->computeToDateFrom($this->computeChargesFromDate());
    }

    protected function computeFixedChargesFromDate(): CarbonImmutable
    {
        if ($this->plan->bill_fixed_charges_monthly) {
            return $this->monthlyService()->computeFixedChargesFromDate();
        }

        if ($this->terminated()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfYear();
        }

        if ($this->plan->payInArrears()) {
            return $this->computeFromDate();
        }

        return $this->subscription->calendar()
            ? $this->baseDate()->startOfYear()
            : $this->previousAnniversaryDay($this->baseDate());
    }

    protected function computeFixedChargesToDate(): CarbonImmutable
    {
        if ($this->plan->bill_fixed_charges_monthly) {
            return $this->monthlyService()->computeFixedChargesToDate();
        }

        return $this->subscription->calendar()
            ? $this->computeFixedChargesFromDate()->endOfYear()->startOfDay()
            : $this->computeToDateFrom($this->computeFixedChargesFromDate());
    }

    /**
     * NOTE: the period is resolved from its own anniversary rather than
     * re-walked from the billing date.
     */
    protected function computeNextEndOfPeriod(): CarbonImmutable
    {
        if ($this->subscription->calendar()) {
            return $this->billingDate()->endOfYear()->startOfDay();
        }

        return $this->computeToDateFrom($this->previousAnniversaryDay($this->billingDate()));
    }

    protected function computePreviousBeginningOfPeriod(CarbonImmutable $date): CarbonImmutable
    {
        return $this->subscription->calendar()
            ? $date->startOfYear()
            : $this->previousAnniversaryDay($date);
    }

    protected function previousAnniversaryDay(CarbonImmutable $date): CarbonImmutable
    {
        $year = $this->periodStartedInLastYear($date) ? $date->year - 1 : $date->year;
        $month = $this->subscriptionAt()->month;
        $day = $this->subscriptionAt()->day;

        return $this->buildDate($year, $month, $day);
    }

    protected function computeDuration(CarbonImmutable $fromDate): int
    {
        if ($this->subscription->calendar()) {
            return self::daysInYear($fromDate->year);
        }

        $periodStart = $this->previousAnniversaryDay($fromDate->startOfDay());

        return (int) $periodStart->diffInDays($this->computeToDateFrom($periodStart)->addDay());
    }

    protected function computeChargesDuration(CarbonImmutable $fromDate): int
    {
        if ($this->plan->bill_charges_monthly) {
            return $this->monthlyService()->computeChargesDuration($fromDate);
        }

        return $this->computeDuration($fromDate);
    }

    protected function computeFixedChargesDuration(CarbonImmutable $fromDate): int
    {
        if ($this->plan->bill_fixed_charges_monthly) {
            return $this->monthlyService()->computeFixedChargesDuration($fromDate);
        }

        return $this->computeDuration($fromDate);
    }

    protected function periodStartedInLastYear(CarbonImmutable $date): bool
    {
        $subscriptionAt = $this->subscriptionAt();

        if ($date->month < $subscriptionAt->month) {
            return true;
        }

        if ($date->month !== $subscriptionAt->month) {
            return false;
        }

        // NOTE: a Feb 29 subscription has its anniversary on Feb 28 in a common
        // year, so the raw subscription day would leave Feb 28 trailing the
        // previous period.
        return $date->day < $this->anniversaryDayIn($date->year, $subscriptionAt->month);
    }

    protected function subYearsWithClamp(CarbonImmutable $date, int $years): CarbonImmutable
    {
        return $date->subYearsNoOverflow($years);
    }
}
