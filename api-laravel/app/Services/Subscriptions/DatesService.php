<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use LogicException;
use App\Models\Plan;
use App\Enums\PlanInterval;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Models\Subscription;
use App\Models\InvoiceSubscription;
use App\Services\Subscriptions\Dates\WeeklyService;
use App\Services\Subscriptions\Dates\YearlyService;
use App\Services\Subscriptions\Dates\MonthlyService;
use App\Services\Subscriptions\Dates\QuarterlyService;
use App\Services\Subscriptions\Dates\SemiannualService;

/**
 * Port of Rails' Subscriptions::DatesService
 * (app/services/subscriptions/dates_service.rb).
 *
 * All "date" values flowing through compute_* are CarbonImmutable instances
 * anchored at 00:00 UTC carrying a plain calendar day; `customer_timezone_shift`
 * converts such a calendar day into the customer-timezone instant (start or
 * end of that day) and back to UTC. Naive DB timestamps are treated as UTC;
 * timezone-aware values never reach the database.
 */
abstract class DatesService
{
    protected Plan $plan;

    /** Billing time — usually the end of the billing period + 1 day; the termination day when terminated. */
    protected CarbonImmutable $billingAt;

    protected ?CarbonImmutable $fromDatetimeCache = null;

    protected ?CarbonImmutable $toDatetimeCache = null;

    protected ?CarbonImmutable $billingDateCache = null;

    protected ?CarbonImmutable $baseDateCache = null;

    protected ?string $timezoneCache = null;

    protected ?InvoiceSubscription $lastInvoiceSubscriptionCache = null;

    public function __construct(protected Subscription $subscription, CarbonInterface|int|null $billingAt, protected bool $currentUsage, /**
     * When composed services (yearly/semiannual → monthly) receive an already
     * calendar-day value (Rails passes a Date), the billing date must not be
     * re-shifted into the customer timezone — a Date#in_time_zone keeps its
     * calendar day.
     */
    protected ?CarbonImmutable $billingDateOverride = null)
    {
        $this->plan = $this->subscription->plan;

        $billingAt ??= now();

        $this->billingAt = is_int($billingAt)
            ? CarbonImmutable::createFromTimestampUTC($billingAt)
            : CarbonImmutable::instance($billingAt)->utc();
    }

    abstract protected function computeBaseDate(): CarbonImmutable;

    abstract protected function computeFromDate(): CarbonImmutable;

    abstract protected function computeToDate(): CarbonImmutable;

    abstract protected function computeChargesFromDate(): CarbonImmutable;

    abstract protected function computeChargesToDate(): CarbonImmutable;

    abstract protected function computeFixedChargesFromDate(): CarbonImmutable;

    abstract protected function computeFixedChargesToDate(): CarbonImmutable;

    abstract protected function computeNextEndOfPeriod(): CarbonImmutable;

    abstract protected function computePreviousBeginningOfPeriod(CarbonImmutable $date): CarbonImmutable;

    abstract protected function computeDuration(CarbonImmutable $fromDate): int;

    /** Rails: `Subscriptions::DatesService.new_instance`. */
    public static function newInstance(Subscription $subscription, CarbonInterface|int|null $billingAt, bool $currentUsage = false): self
    {
        $interval = $subscription->plan->interval;

        $klass = match (PlanInterval::tryFrom((int) $interval)) {
            PlanInterval::Weekly => WeeklyService::class,
            PlanInterval::Monthly => MonthlyService::class,
            PlanInterval::Yearly => YearlyService::class,
            PlanInterval::Quarterly => QuarterlyService::class,
            PlanInterval::Semiannual => SemiannualService::class,
            default => throw new LogicException('NotImplementedError'), // port of Rails' raise(NotImplementedError)
        };

        return new $klass($subscription, $billingAt, $currentUsage);
    }

    /** Rails: `charge_pay_in_advance_interval`. */
    public static function chargePayInAdvanceInterval(int $timestamp, Subscription $subscription): array
    {
        $dateService = self::newInstance($subscription, CarbonImmutable::createFromTimestampUTC($timestamp), true);

        $chargesFrom = $dateService->chargesFromDatetime();
        $chargesTo = $dateService->chargesToDatetime();

        return [
            'charges_from_date' => $chargesFrom?->startOfDay(),
            'charges_to_date' => $chargesTo?->startOfDay(),
        ];
    }

    /** Rails: `fixed_charge_pay_in_advance_interval`. */
    public static function fixedChargePayInAdvanceInterval(int $timestamp, Subscription $subscription): array
    {
        $dateService = self::newInstance($subscription, CarbonImmutable::createFromTimestampUTC($timestamp), true);

        return [
            'fixed_charges_from_datetime' => $dateService->fixedChargesFromDatetime(),
            'fixed_charges_to_datetime' => $dateService->fixedChargesToDatetime(),
            'fixed_charges_duration' => $dateService->fixedChargesDurationInDays(),
        ];
    }

    // -- Public boundary API -------------------------------------------------------

    public function fromDatetime(): ?CarbonImmutable
    {
        if ($this->fromDatetimeCache !== null) {
            return $this->fromDatetimeCache;
        }

        if ($this->subscription->started_at === null) {
            return null;
        }

        $fromDatetime = $this->customerTimezoneShift($this->computeFromDate());

        // NOTE: On first billing period, subscription might start after the
        // computed start of period — the invoice should start on the
        // subscription date, not on the period boundary.
        $startedAt = $this->startedAtUtc();
        if ($startedAt !== null && $fromDatetime->lt($startedAt)) {
            $fromDatetime = CarbonImmutable::instance($this->subscription->started_at)
                ->utc()
                ->setTimezone($this->timezone())
                ->startOfDay()
                ->utc();
        }

        return $this->fromDatetimeCache = $fromDatetime;
    }

    public function toDatetime(): ?CarbonImmutable
    {
        if ($this->toDatetimeCache !== null) {
            return $this->toDatetimeCache;
        }

        if ($this->subscription->started_at === null) {
            return null;
        }

        $toDatetime = $this->customerTimezoneShift($this->computeToDate(), true);

        $terminatedAt = $this->subscription->terminated_at !== null
            ? CarbonImmutable::instance($this->subscription->terminated_at)->utc()->roundSecond()
            : null;

        if ($terminatedAt !== null && $this->subscription->terminatedAt($this->billingAt) && $toDatetime->gt($terminatedAt)) {
            $toDatetime = $terminatedAt;
        }

        $startedAt = $this->startedAtUtc();
        if ($startedAt !== null && $toDatetime->lt($startedAt)) {
            $toDatetime = $startedAt;
        }

        return $this->toDatetimeCache = $toDatetime;
    }

    public function chargesFromDatetime(): ?CarbonImmutable
    {
        if ($this->subscription->started_at === null) {
            return null;
        }

        if (! $this->shouldFillChargesBoundaries()) {
            return null;
        }

        $datetime = $this->customerTimezoneShift($this->computeChargesFromDate());

        // NOTE: If the customer applicable timezone changes during a billing
        // period, invoice bounds must not overlap or leave a hole.
        $previousChargeTo = $this->timezoneHasChanged() ? $this->previousChargeToDatetime() : null;
        if ($previousChargeTo !== null) {
            $newDatetime = $previousChargeTo->addSecond();

            // NOTE: 26 hours is the maximum time difference between two places in the world
            if (abs($datetime->getTimestamp() - $newDatetime->getTimestamp()) / 3600 < 26) {
                $datetime = $newDatetime;
            }
        }

        $startedAt = $this->startedAtUtc();
        if ($startedAt !== null && $datetime->lt($startedAt)) {
            $datetime = $startedAt;
        }

        return $datetime;
    }

    public function chargesToDatetime(): ?CarbonImmutable
    {
        if ($this->subscription->started_at === null) {
            return null;
        }

        if (! $this->shouldFillChargesBoundaries()) {
            return null;
        }

        $datetime = $this->customerTimezoneShift($this->computeChargesToDate(), true);

        if ($this->subscription->terminated() && $this->subscription->terminated_at !== null
            && CarbonImmutable::instance($this->subscription->terminated_at)->utc()->lte($datetime)) {
            $datetime = CarbonImmutable::instance($this->subscription->terminated_at)->utc();
        }

        $startedAt = $this->startedAtUtc();
        if ($startedAt !== null && $datetime->lt($startedAt)) {
            $datetime = $startedAt;
        }

        return $datetime;
    }

    public function fixedChargesFromDatetime(): ?CarbonImmutable
    {
        if ($this->subscription->started_at === null) {
            return null;
        }

        if (! $this->shouldFillFixedChargesBoundaries()) {
            return null;
        }

        $datetime = $this->customerTimezoneShift($this->computeFixedChargesFromDate());

        $previousFixedChargeTo = $this->timezoneHasChanged() ? $this->previousFixedChargeToDatetime() : null;
        if ($previousFixedChargeTo !== null) {
            $newDatetime = $previousFixedChargeTo->addSecond();

            if (abs($datetime->getTimestamp() - $newDatetime->getTimestamp()) / 3600 < 26) {
                $datetime = $newDatetime;
            }
        }

        $startedAt = $this->startedAtUtc();
        if ($startedAt !== null && $datetime->lt($startedAt)) {
            $datetime = $startedAt;
        }

        return $datetime;
    }

    public function fixedChargesToDatetime(): ?CarbonImmutable
    {
        if (! $this->shouldFillFixedChargesBoundaries()) {
            return null;
        }

        return $this->fixedChargesPeriodToDatetime();
    }

    /**
     * End of the current fixed-charges period, regardless of whether fixed
     * charges are billed this cycle.
     */
    public function fixedChargesPeriodToDatetime(): ?CarbonImmutable
    {
        if ($this->subscription->started_at === null) {
            return null;
        }

        $datetime = $this->customerTimezoneShift($this->computeFixedChargesToDate(), true);

        if ($this->subscription->terminated() && $this->subscription->terminated_at !== null
            && CarbonImmutable::instance($this->subscription->terminated_at)->utc()->lte($datetime)) {
            $datetime = CarbonImmutable::instance($this->subscription->terminated_at)->utc();
        }

        $startedAt = $this->startedAtUtc();
        if ($startedAt !== null && $datetime->lt($startedAt)) {
            $datetime = $startedAt;
        }

        return $datetime;
    }

    public function nextEndOfPeriod(): CarbonImmutable
    {
        return $this->customerTimezoneShift($this->computeNextEndOfPeriod(), true);
    }

    public function endOfPeriod(): CarbonImmutable
    {
        return $this->customerTimezoneShift($this->computeToDate(), true);
    }

    /**
     * Start of the billing period that follows the current one, at the
     * beginning of the day in the customer timezone (kept timezone-aware,
     * like Rails).
     */
    public function nextPeriodStartedAt(): CarbonImmutable
    {
        return $this->endOfPeriod()
            ->addDay()
            ->setTimezone($this->timezone())
            ->startOfDay();
    }

    /** Rails: `previous_beginning_of_period` — beginning of the previous period. */
    public function previousBeginningOfPeriod(bool $currentPeriod = false): CarbonImmutable
    {
        $date = $this->baseDate();
        if ($currentPeriod) {
            $date = $this->billingDate();
        }

        return $this->customerTimezoneShift($this->computePreviousBeginningOfPeriod($date));
    }

    public function singleDayPrice(?CarbonImmutable $optionalFromDate = null, ?int $planAmountCents = null): float
    {
        $duration = $this->computeDuration($optionalFromDate ?? $this->computeFromDate());

        return ($planAmountCents ?? (int) $this->plan->amount_cents) / $duration;
    }

    public function chargesDurationInDays(): int
    {
        return $this->computeChargesDuration($this->computeChargesFromDate());
    }

    public function fixedChargesDurationInDays(): int
    {
        return $this->computeFixedChargesDuration($this->computeFixedChargesFromDate());
    }

    public function firstMonthInYearlyPeriod(): bool
    {
        return false;
    }

    public function firstMonthInSemiannualPeriod(): bool
    {
        return false;
    }

    /** Rails: Time.days_in_month(month, year). */
    protected static function daysInMonth(int $month, int $year): int
    {
        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->daysInMonth;
    }

    /** Rails: Time.days_in_year(year). */
    protected static function daysInYear(int $year): int
    {
        return CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'UTC')->isLeapYear() ? 366 : 365;
    }

    // -- Abstract / overridable computation hooks -----------------------------------

    /** Determines if charges should be billed this cycle. */
    protected function shouldFillChargesBoundaries(): bool
    {
        return true;
    }

    /** Determines if fixed charges should be billed this cycle. */
    protected function shouldFillFixedChargesBoundaries(): bool
    {
        return true;
    }

    protected function computeChargesDuration(CarbonImmutable $fromDate): int
    {
        return $this->computeDuration($fromDate);
    }

    protected function computeFixedChargesDuration(CarbonImmutable $fromDate): int
    {
        return $this->computeChargesDuration($fromDate);
    }

    // -- Shared helpers -------------------------------------------------------------

    protected function customer()
    {
        return $this->subscription->customer;
    }

    protected function timezone(): string
    {
        return $this->timezoneCache ??= $this->customer()->applicableTimezone();
    }

    /** The subscription's started_at as a UTC instant. */
    protected function startedAtUtc(): ?CarbonImmutable
    {
        return $this->subscription->started_at !== null
            ? CarbonImmutable::instance($this->subscription->started_at)->utc()
            : null;
    }

    /** subscription_at as a wall-clock value in the customer timezone. */
    protected function subscriptionAt(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->subscription->subscription_at)
            ->utc()
            ->setTimezone($this->timezone());
    }

    /** The billing_at instant, as a calendar day in the customer timezone. */
    protected function billingDate(): CarbonImmutable
    {
        if ($this->billingDateOverride !== null) {
            return $this->billingDateCache ??= $this->billingDateOverride->startOfDay();
        }

        return $this->billingDateCache ??= $this->billingAt->setTimezone($this->timezone())->startOfDay();
    }

    protected function baseDate(): CarbonImmutable
    {
        if ($this->baseDateCache !== null) {
            return $this->baseDateCache;
        }

        if ($this->currentUsage) {
            return $this->baseDateCache = $this->billingDate();
        }

        return $this->baseDateCache = $this->computeBaseDate();
    }

    /**
     * NOTE: This method converts a DAY expressed in the customer timezone
     * into a proper UTC datetime. Example: `2024-03-01` in `America/New_York`
     * becomes `2024-03-01T05:00:00 UTC`.
     */
    protected function customerTimezoneShift(CarbonImmutable $date, bool $endOfDay = false): CarbonImmutable
    {
        $result = CarbonImmutable::parse($date->format('Y-m-d'), $this->timezone());

        if ($endOfDay) {
            $result = $result->endOfDay();
        }

        return $result->utc();
    }

    /** Rails: `last_invoice_subscription` — latest by charges_to_datetime. */
    protected function lastInvoiceSubscription(): ?InvoiceSubscription
    {
        if ($this->lastInvoiceSubscriptionCache !== null) {
            return $this->lastInvoiceSubscriptionCache;
        }

        return $this->lastInvoiceSubscriptionCache = $this->subscription->invoiceSubscriptions()
            ->orderByRaw('COALESCE(invoice_subscriptions.to_datetime, invoice_subscriptions.created_at) DESC')
            ->first();
    }

    protected function timezoneHasChanged(): bool
    {
        $last = $this->lastInvoiceSubscription();

        if ($last === null) {
            return false;
        }

        return $last->invoice->timezone !== $this->timezone();
    }

    protected function previousChargeToDatetime(): ?CarbonImmutable
    {
        $last = $this->lastInvoiceSubscription();

        return $last?->charges_to_datetime !== null
            ? CarbonImmutable::instance($last->charges_to_datetime)->utc()
            : null;
    }

    protected function previousFixedChargeToDatetime(): ?CarbonImmutable
    {
        $last = $this->lastInvoiceSubscription();

        return $last?->fixed_charges_to_datetime !== null
            ? CarbonImmutable::instance($last->fixed_charges_to_datetime)->utc()
            : null;
    }

    /**
     * NOTE: In case of termination or upgrade when we are terminating old
     * plan (paying in arrear), we should take to the beginning of the billing
     * period.
     */
    protected function terminatedPayInArrears(): bool
    {
        return $this->subscription->terminatedAt($this->billingAt)
            && $this->plan->payInArrears()
            && ! $this->subscription->downgraded();
    }

    protected function terminated(): bool
    {
        return $this->subscription->terminatedAt($this->billingAt)
            && $this->subscription->nextSubscription() === null;
    }

    /**
     * NOTE: Handle leap years and anniversary date > 28. `day = 0` rolls back
     * to the last day of the previous month; days beyond the month's length
     * clamp to it.
     */
    protected function buildDate(int $year, int $month, int $day): CarbonImmutable
    {
        if ($day === 0) {
            $day = 31;
            $month--;

            if ($month === 0) {
                $month = 12;
                $year--;
            }
        }

        $daysCountInMonth = self::daysInMonth($month, $year);
        if ($daysCountInMonth < $day) {
            $day = $daysCountInMonth;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }

    protected function lastDayOfMonth(CarbonImmutable $date): bool
    {
        return $date->day === $date->daysInMonth;
    }

    /**
     * NOTE: the anniversary day of a month that is shorter than the
     * subscription day, clamped to that month.
     */
    protected function anniversaryDayIn(int $year, int $month): int
    {
        return min($this->subscriptionAt()->day, self::daysInMonth($month, $year));
    }

    /** Ruby `Date#>>` / `- 1.month` family — months shift with day clamping. */
    protected function addMonthsWithClamp(CarbonImmutable $date, int $months): CarbonImmutable
    {
        return $date->addMonthsNoOverflow($months);
    }

    protected function subMonthsWithClamp(CarbonImmutable $date, int $months): CarbonImmutable
    {
        return $date->subMonthsNoOverflow($months);
    }

    /** Rails: Date#beginning_of_half_year (months 1-6 / 7-12). */
    protected function beginningOfHalfYear(CarbonImmutable $date): CarbonImmutable
    {
        $month = $date->month <= 6 ? 1 : 7;

        return CarbonImmutable::create($date->year, $month, 1, 0, 0, 0, 'UTC');
    }

    /** Rails: Date#end_of_half_year. */
    protected function endOfHalfYear(CarbonImmutable $date): CarbonImmutable
    {
        $month = $date->month <= 6 ? 6 : 12;

        return CarbonImmutable::create($date->year, $month, 1, 0, 0, 0, 'UTC')->endOfMonth()->startOfDay();
    }
}
