<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\Dates;

use App\Services\Subscriptions\DatesService;
use Carbon\CarbonImmutable;

/**
 * Port of Rails' Subscriptions::Dates::SemiannualService.
 */
class SemiannualService extends DatesService
{
    public function firstMonthInSemiannualPeriod(): bool
    {
        if ($this->subscription->calendar()) {
            return in_array($this->billingDate()->month, [1, 7], true);
        }

        $startMonth = $this->subscriptionAt()->month;
        $secondHalfMonth = ($startMonth <= 6) ? $startMonth + 6 : $startMonth - 6;

        return in_array($this->billingFromDate()->month, [$startMonth, $secondHalfMonth], true);
    }

    public function firstMonthInFirstSemiannualPeriod(): bool
    {
        if ($this->subscription->calendar()) {
            $billingDate = $this->billingDate();

            return in_array($billingDate->month, [1, 7], true) && $billingDate->year === $this->subscriptionAt()->year;
        }

        return $this->billingFromDate()->month === $this->subscriptionAt()->month
            && $this->billingFromDate()->year === $this->subscriptionAt()->year;
    }

    protected function shouldFillChargesBoundaries(): bool
    {
        if ($this->currentUsage) {
            return true;
        }

        if ($this->plan->bill_charges_monthly) {
            return true;
        }

        if ($this->plan->bill_fixed_charges_monthly) {
            return $this->firstMonthInSemiannualPeriod();
        }

        return true;
    }

    protected function shouldFillFixedChargesBoundaries(): bool
    {
        if ($this->plan->bill_fixed_charges_monthly) {
            return true;
        }

        if ($this->plan->bill_charges_monthly) {
            return $this->firstMonthInSemiannualPeriod();
        }

        return true;
    }

    protected function monthlyService(): MonthlyService
    {
        return new MonthlyService($this->subscription, $this->billingDate(), $this->currentUsage, $this->billingDate());
    }

    protected function billingFromDate(): CarbonImmutable
    {
        return $this->monthlyService()->computeFromDate($this->billingDate());
    }

    public function computeFromDate(?CarbonImmutable $date = null): CarbonImmutable
    {
        $date ??= $this->baseDate();

        if ($this->plan->pay_in_advance || $this->terminatedPayInArrears()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->beginningOfHalfYear($this->billingDate());
        }

        return $this->subscription->anniversary()
            ? $this->previousAnniversaryDay($date)
            : $this->beginningOfHalfYear($date);
    }

    protected function computeChargesFromDate(): CarbonImmutable
    {
        if ($this->plan->bill_charges_monthly) {
            return $this->monthlyService()->computeChargesFromDate();
        }

        if ($this->terminated()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->beginningOfHalfYear($this->billingDate());
        }

        if ($this->plan->payInArrears()) {
            return $this->computeFromDate();
        }

        return $this->subscription->calendar()
            ? $this->beginningOfHalfYear($this->baseDate())
            : $this->previousAnniversaryDay($this->baseDate());
    }

    protected function computeChargesToDate(): CarbonImmutable
    {
        if ($this->plan->bill_charges_monthly) {
            return $this->monthlyService()->computeChargesToDate();
        }

        return $this->subscription->calendar()
            ? $this->endOfHalfYear($this->computeChargesFromDate())
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
                : $this->beginningOfHalfYear($this->billingDate());
        }

        if ($this->plan->payInArrears()) {
            return $this->computeFromDate();
        }

        return $this->subscription->calendar()
            ? $this->beginningOfHalfYear($this->baseDate())
            : $this->previousAnniversaryDay($this->baseDate());
    }

    protected function computeFixedChargesToDate(): CarbonImmutable
    {
        if ($this->plan->bill_fixed_charges_monthly) {
            return $this->monthlyService()->computeFixedChargesToDate();
        }

        return $this->subscription->calendar()
            ? $this->endOfHalfYear($this->computeFixedChargesFromDate())
            : $this->computeToDateFrom($this->computeFixedChargesFromDate());
    }

    protected function computeDuration(CarbonImmutable $fromDate): int
    {
        $periodStart = $this->computePreviousBeginningOfPeriod($fromDate->startOfDay());

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

    protected function computeBaseDate(): CarbonImmutable
    {
        $billingDate = $this->billingDate();

        // NOTE: if subscription anniversary is on last day of month and current
        // month days count is less than month anniversary day count, we need to
        // use the last day of the previous month
        if ($this->subscription->anniversary() && $this->lastDayOfMonth($billingDate) && ($billingDate->day < $this->subscriptionAt()->day)) {
            $previousHalfYear = $this->subMonthsWithClamp($billingDate, 6);

            if ($previousHalfYear->endOfMonth()->day >= $this->subscriptionAt()->day) {
                return $previousHalfYear->endOfMonth()->startOfDay()->setDay($this->subscriptionAt()->day);
            }

            return $previousHalfYear->endOfMonth()->startOfDay();
        }

        return $this->subMonthsWithClamp($billingDate, 6);
    }

    protected function computeToDate(): CarbonImmutable
    {
        return $this->computeToDateFrom($this->computeFromDate());
    }

    protected function computeToDateFrom(CarbonImmutable $fromDate): CarbonImmutable
    {
        if ($this->subscription->calendar()
            || ($this->subscriptionAt()->day === 1 && in_array($this->subscriptionAt()->month, [1, 7], true))) {
            return $this->endOfHalfYear($fromDate);
        }

        return $this->nextAnniversary($fromDate)->subDay()->startOfDay();
    }

    /**
     * `compute_to_date` is the day before this, so an anniversary always opens
     * a period and never also closes the previous one.
     */
    protected function nextAnniversary(CarbonImmutable $fromDate): CarbonImmutable
    {
        $nextPeriodMonth = $this->addMonthsWithClamp($fromDate->startOfDay(), 6);

        return $this->buildDate(
            $nextPeriodMonth->year,
            $nextPeriodMonth->month,
            $this->anniversaryDayIn($nextPeriodMonth->year, $nextPeriodMonth->month),
        );
    }

    protected function computeNextEndOfPeriod(): CarbonImmutable
    {
        if ($this->subscription->calendar()) {
            return $this->endOfHalfYear($this->billingDate());
        }

        return $this->computeToDateFrom($this->previousAnniversaryDay($this->billingDate()));
    }

    protected function computePreviousBeginningOfPeriod(CarbonImmutable $date): CarbonImmutable
    {
        return $this->subscription->calendar()
            ? $this->beginningOfHalfYear($date)
            : $this->previousAnniversaryDay($date);
    }

    protected function previousAnniversaryDay(CarbonImmutable $date): CarbonImmutable
    {
        $billingMonths = $this->billingMonths();

        $year = null;
        $month = null;

        // This is the case when we terminate subscription on February 10 but
        // anniversary date is on 5 of March. In that case we need to fetch
        // billing period in previous year
        if ($this->shouldFindBillingDateInPreviousYear($date, $billingMonths)) {
            $year = $date->year - 1;
            $month = $billingMonths[1];
        } elseif ($this->shouldFindPreviousBillingDate($date, $billingMonths)) {
            $year = $date->year;
            $month = $this->rfindBillingMonth($billingMonths, $date->month);
        } else {
            $year = $date->year;
            $month = $date->month;
        }

        return $this->buildDate($year, $month, $this->anniversaryDayIn($year, $month));
    }

    /** Ruby: `[m%12, (m+6)%12].map { 12 if 0 }.sort` — sorted billing months. */
    protected function billingMonths(): array
    {
        $subscriptionMonth = $this->subscriptionAt()->month;

        $normalize = fn (int $m) => (($m % 12) === 0) ? 12 : ($m % 12);

        $months = [
            $normalize($subscriptionMonth),
            $normalize($subscriptionMonth + 6),
        ];
        sort($months);

        return $months;
    }

    /**
     * @param  list<int>  $billingMonths
     */
    protected function shouldFindBillingDateInPreviousYear(CarbonImmutable $date, array $billingMonths): bool
    {
        if ($date->month < $billingMonths[0]) {
            return true;
        }

        return ($date->month === $billingMonths[0]) && $this->shouldFindPreviousBillingDate($date, $billingMonths);
    }

    /**
     * @param  list<int>  $billingMonths
     */
    protected function shouldFindPreviousBillingDate(CarbonImmutable $date, array $billingMonths): bool
    {
        if (! in_array($date->month, $billingMonths, true)) {
            return true;
        }

        $anniversaryDay = $this->anniversaryDayIn($date->year, $date->month);

        return $date->day < $anniversaryDay;
    }

    /** Ruby `rfind { |m| m < date.month }` — last billing month strictly before. */
    protected function rfindBillingMonth(array $billingMonths, int $month): int
    {
        for ($i = count($billingMonths) - 1; $i >= 0; $i--) {
            if ($billingMonths[$i] < $month) {
                return $billingMonths[$i];
            }
        }

        throw new \LogicException('No billing month before the requested month');
    }
}
