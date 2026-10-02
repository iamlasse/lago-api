<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\Dates;

use LogicException;
use Carbon\CarbonImmutable;
use App\Services\Subscriptions\DatesService;

/**
 * Port of Rails' Subscriptions::Dates::QuarterlyService.
 */
class QuarterlyService extends DatesService
{
    public function computeFromDate(?CarbonImmutable $date = null): CarbonImmutable
    {
        $date ??= $this->baseDate();

        if ($this->plan->pay_in_advance || $this->terminatedPayInArrears()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfQuarter();
        }

        return $this->subscription->anniversary()
            ? $this->previousAnniversaryDay($date)
            : $date->startOfQuarter();
    }

    protected function computeChargesFromDate(): CarbonImmutable
    {
        // NOTE: when subscription is terminated, we must bill on the current period
        if ($this->terminated()) {
            return $this->subscription->anniversary()
                ? $this->previousAnniversaryDay($this->billingDate())
                : $this->billingDate()->startOfQuarter();
        }

        if ($this->plan->payInArrears()) {
            return $this->computeFromDate();
        }

        return $this->subscription->calendar()
            ? $this->baseDate()->startOfQuarter()
            : $this->previousAnniversaryDay($this->baseDate());
    }

    protected function computeChargesToDate(): CarbonImmutable
    {
        return $this->subscription->calendar()
            ? $this->computeChargesFromDate()->endOfQuarter()->startOfDay()
            : $this->computeToDateFrom($this->computeChargesFromDate());
    }

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
            $previousQuarterMonth = $this->subMonthsWithClamp($billingDate, 3);

            if ($previousQuarterMonth->endOfMonth()->day >= $this->subscriptionAt()->day) {
                return $previousQuarterMonth->endOfMonth()->startOfDay()->setDay($this->subscriptionAt()->day);
            }

            return $previousQuarterMonth->endOfMonth()->startOfDay();
        }

        return $this->subMonthsWithClamp($billingDate, 3);
    }

    protected function computeToDate(): CarbonImmutable
    {
        return $this->computeToDateFrom($this->computeFromDate());
    }

    protected function computeToDateFrom(CarbonImmutable $fromDate): CarbonImmutable
    {
        if ($this->subscription->calendar()
            || ($this->subscriptionAt()->day === 1 && in_array($this->subscriptionAt()->month, [1, 4, 7, 10], true))) {
            return $fromDate->endOfQuarter()->startOfDay();
        }

        return $this->nextAnniversary($fromDate)->subDay()->startOfDay();
    }

    /**
     * `compute_to_date` is the day before this, so an anniversary always opens
     * a period and never also closes the previous one.
     */
    protected function nextAnniversary(CarbonImmutable $fromDate): CarbonImmutable
    {
        $nextPeriodMonth = $this->addMonthsWithClamp($fromDate->startOfDay(), 3);

        return $this->buildDate(
            $nextPeriodMonth->year,
            $nextPeriodMonth->month,
            $this->anniversaryDayIn($nextPeriodMonth->year, $nextPeriodMonth->month),
        );
    }

    /**
     * NOTE: the period is resolved from its own anniversary, not from
     * `billing_date.month`, which is not necessarily a billing month.
     */
    protected function computeNextEndOfPeriod(): CarbonImmutable
    {
        if ($this->subscription->calendar()) {
            return $this->billingDate()->endOfQuarter()->startOfDay();
        }

        return $this->computeToDateFrom($this->previousAnniversaryDay($this->billingDate()));
    }

    protected function computePreviousBeginningOfPeriod(CarbonImmutable $date): CarbonImmutable
    {
        return $this->subscription->calendar()
            ? $date->startOfQuarter()
            : $this->previousAnniversaryDay($date);
    }

    /**
     * @param  list<int>  $billingMonths
     */
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
            $month = $billingMonths[3];
        } elseif ($this->shouldFindPreviousBillingDate($date, $billingMonths)) {
            // In case of termination that is in the middle of the year, previous
            // period anniversary date has to be returned
            $year = $date->year;
            $month = $this->rfindBillingMonth($billingMonths, $date->month);
        } else {
            $year = $date->year;
            $month = $date->month;
        }

        return $this->buildDate($year, $month, $this->anniversaryDayIn($year, $month));
    }

    /** Ruby: `[m, (m+3)%12, (m+6)%12, (m+9)%12].map { 12 if 0 }.sort` — sorted billing months. */
    protected function billingMonths(): array
    {
        $subscriptionMonth = $this->subscriptionAt()->month;

        $normalize = fn (int $m) => (($m % 12) === 0) ? 12 : ($m % 12);

        $months = [
            $normalize($subscriptionMonth),
            $normalize($subscriptionMonth + 3),
            $normalize($subscriptionMonth + 6),
            $normalize($subscriptionMonth + 9),
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
        // NOTE: checked first. A non-billing month always resolves to an earlier
        // billing month, and falling through to the same-month branch would
        // instead walk the period forward one month at a time.
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

        // Guarded by should_find_previous_billing_date? — never reached in Rails.
        throw new LogicException('No billing month before the requested month');
    }
}
