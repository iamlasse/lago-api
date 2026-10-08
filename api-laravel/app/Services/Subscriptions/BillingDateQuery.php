<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Enums\BillingTime;
use App\Enums\PlanInterval;
use Carbon\CarbonInterface;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Port of Rails' Subscriptions::BillingDateQuery
 * (app/services/subscriptions/billing_date_query.rb) — filters a
 * subscriptions scope down to the ones whose billing period rolls over
 * (i.e. is billed) on `timestamp`'s date, in the customer's applicable
 * timezone.
 *
 * The calendar logic (calendar vs anniversary, every interval, end-of-month
 * and leap-year edge cases, timezone) is intentionally identical to the
 * periodic billing selection in Subscriptions\Organizations\BillingService
 * (the port of Rails' Subscriptions::OrganizationBillingService). It is
 * expressed here as a single OR-ed WHERE so it can be composed onto any
 * subscriptions scope.
 *
 * NOTE: the given scope must allow joining `plans`, `customers` and
 *       `billing_entities` (needed for the timezone-aware date
 *       comparisons) — the joins are applied here.
 */
class BillingDateQuery extends BaseService
{
    public function __construct(
        private readonly Builder $subscriptions,
        private readonly CarbonInterface $timestamp,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscriptions');

        $today = $this->timestamp->utc()->toDateTimeString();

        $result->subscriptions = $this->subscriptions
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->join('customers', 'customers.id', '=', 'subscriptions.customer_id')
            ->join('billing_entities', 'billing_entities.id', '=', 'customers.billing_entity_id')
            ->where(function (Builder $query) use ($today): void {
                $this->whereScoped($query, $this->scoped('calendar', 'weekly', $this->weeklyCalendar()), $today, false);
                $this->whereScoped($query, $this->scoped('calendar', 'monthly', $this->monthlyCalendar()), $today);
                $this->whereScoped($query, $this->scoped('calendar', 'quarterly', $this->quarterlyCalendar()), $today);
                $this->whereScoped($query, $this->scoped('calendar', 'semiannual', $this->semiannualWithMonthlyCharges()), $today);
                $this->whereScoped($query, $this->scoped('calendar', 'semiannual', $this->semiannualWithMonthlyFixedCharges()), $today);
                $this->whereScoped($query, $this->scoped('calendar', 'semiannual', $this->semiannualCalendar()), $today);
                $this->whereScoped($query, $this->scoped('calendar', 'yearly', $this->yearlyWithMonthlyCharges()), $today);
                $this->whereScoped($query, $this->scoped('calendar', 'yearly', $this->yearlyWithMonthlyFixedCharges()), $today);
                $this->whereScoped($query, $this->scoped('calendar', 'yearly', $this->yearlyCalendar()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'weekly', $this->weeklyAnniversary()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'monthly', $this->anniversaryDay()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'quarterly', $this->quarterlyAnniversaryMonth().' AND '.$this->anniversaryDay()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'semiannual', $this->planBillChargesMonthly().' AND '.$this->anniversaryDay()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'semiannual', $this->planBillFixedChargesMonthlyOnly().' AND '.$this->anniversaryDay()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'semiannual', $this->semiannualAnniversaryMonth().' AND '.$this->anniversaryDay()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'yearly', $this->planBillChargesMonthly().' AND '.$this->anniversaryDay()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'yearly', $this->planBillFixedChargesMonthlyOnly().' AND '.$this->anniversaryDay()), $today);
                $this->whereScoped($query, $this->scoped('anniversary', 'yearly', $this->yearlyAnniversaryMonth().' AND '.$this->yearlyAnniversaryDay()), $today);
            });

        return $result;
    }

    /**
     * Adds one OR-ed billing-day condition. Every `?` in the fragment is the
     * same `today` timestamp (Rails' single `:today` binding).
     */
    private function whereScoped(Builder $query, string $sql, string $today, bool $or = true): void
    {
        $bindings = array_fill(0, mb_substr_count($sql, '?'), $today);

        $or ? $query->orWhereRaw($sql, $bindings) : $query->whereRaw($sql, $bindings);
    }

    /**
     * @param  'calendar'|'anniversary'  $billingTime
     */
    private function scoped(string $billingTime, string $interval, string $condition): string
    {
        $billingTimeValue = (string) match ($billingTime) {
            'calendar' => BillingTime::Calendar->value,
            'anniversary' => BillingTime::Anniversary->value,
        };
        $intervalValue = (string) match ($interval) {
            'weekly' => PlanInterval::Weekly->value,
            'monthly' => PlanInterval::Monthly->value,
            'quarterly' => PlanInterval::Quarterly->value,
            'semiannual' => PlanInterval::Semiannual->value,
            'yearly' => PlanInterval::Yearly->value,
        };

        return "subscriptions.billing_time = {$billingTimeValue} "
            ."AND plans.interval = {$intervalValue} "
            ."AND ({$condition})";
    }

    /** Rails: Utils::Timezone.at_time_zone_sql, on the default aliases. */
    private function tz(): string
    {
        return "::timestamptz AT TIME ZONE COALESCE(customers.timezone, billing_entities.timezone, 'UTC')";
    }

    private function weeklyCalendar(): string
    {
        return "EXTRACT(ISODOW FROM (?{$this->tz()})) = 1";
    }

    private function monthlyCalendar(): string
    {
        return "DATE_PART('day', (?{$this->tz()})) = 1";
    }

    private function quarterlyCalendar(): string
    {
        return "DATE_PART('month', (?{$this->tz()})) IN (1, 4, 7, 10) AND DATE_PART('day', (?{$this->tz()})) = 1";
    }

    private function semiannualCalendar(): string
    {
        return "DATE_PART('month', (?{$this->tz()})) IN (1, 7) AND DATE_PART('day', (?{$this->tz()})) = 1";
    }

    private function yearlyCalendar(): string
    {
        return "DATE_PART('month', (?{$this->tz()})) = 1 AND DATE_PART('day', (?{$this->tz()})) = 1";
    }

    private function semiannualWithMonthlyCharges(): string
    {
        return "DATE_PART('day', (?{$this->tz()})) = 1 AND {$this->planBillChargesMonthly()}";
    }

    private function semiannualWithMonthlyFixedCharges(): string
    {
        return "DATE_PART('day', (?{$this->tz()})) = 1 AND {$this->planBillFixedChargesMonthlyOnly()}";
    }

    private function yearlyWithMonthlyCharges(): string
    {
        return "DATE_PART('day', (?{$this->tz()})) = 1 AND {$this->planBillChargesMonthly()}";
    }

    private function yearlyWithMonthlyFixedCharges(): string
    {
        return "DATE_PART('day', (?{$this->tz()})) = 1 AND {$this->planBillFixedChargesMonthlyOnly()}";
    }

    private function weeklyAnniversary(): string
    {
        return "EXTRACT(ISODOW FROM (subscriptions.subscription_at{$this->tz()})) = EXTRACT(ISODOW FROM (?{$this->tz()}))";
    }

    /**
     * The subscription_at day-of-month matches today, accounting for short
     * months (e.g. a sub anchored on the 31st bills on the 30th/28th when
     * the month is shorter).
     */
    private function anniversaryDay(): string
    {
        $tz = $this->tz();
        $endOfMonth = $this->endOfMonth();

        return <<<SQL
            DATE_PART('day', (subscriptions.subscription_at{$tz})) = ANY (
              CASE WHEN DATE_PART('day', ({$endOfMonth})) = DATE_PART('day', ?{$tz})
              THEN
                (SELECT ARRAY(SELECT generate_series(DATE_PART('day', ?{$tz})::integer, 31)))
              ELSE
                (SELECT ARRAY[DATE_PART('day', ?{$tz})])
              END
            )
            SQL;
    }

    private function quarterlyAnniversaryMonth(): string
    {
        $tz = $this->tz();

        return <<<SQL
            (
              CASE WHEN MOD(CAST(DATE_PART('month', (subscriptions.subscription_at{$tz})) AS INTEGER), 3) = 0
              THEN
                (DATE_PART('month', ?{$tz}) IN (3, 6, 9, 12))
              ELSE (
                DATE_PART('month', (subscriptions.subscription_at{$tz})) = DATE_PART('month', ?{$tz})
                  OR MOD(CAST(DATE_PART('month', (subscriptions.subscription_at{$tz})) + 3 AS INTEGER), 12) = DATE_PART('month', ?{$tz})
                  OR MOD(CAST(DATE_PART('month', (subscriptions.subscription_at{$tz})) + 6 AS INTEGER), 12) = DATE_PART('month', ?{$tz})
                  OR MOD(CAST(DATE_PART('month', (subscriptions.subscription_at{$tz})) + 9 AS INTEGER), 12) = DATE_PART('month', ?{$tz})
              )
              END
            )
            SQL;
    }

    private function semiannualAnniversaryMonth(): string
    {
        $tz = $this->tz();

        return <<<SQL
            (
              CASE WHEN MOD(CAST(DATE_PART('month', (subscriptions.subscription_at{$tz})) AS INTEGER), 6) = 0
              THEN
                (DATE_PART('month', ?{$tz}) IN (6, 12))
              ELSE (
                DATE_PART('month', (subscriptions.subscription_at{$tz})) = DATE_PART('month', ?{$tz})
                  OR MOD(CAST(DATE_PART('month', (subscriptions.subscription_at{$tz})) + 6 AS INTEGER), 12) = DATE_PART('month', ?{$tz})
              )
              END
            )
            SQL;
    }

    private function yearlyAnniversaryMonth(): string
    {
        $tz = $this->tz();

        return "DATE_PART('month', (subscriptions.subscription_at{$tz})) = DATE_PART('month', ?{$tz})";
    }

    private function yearlyAnniversaryDay(): string
    {
        $tz = $this->tz();
        $endOfMonth = $this->endOfMonth();

        return <<<SQL
            DATE_PART('day', (subscriptions.subscription_at{$tz})) = ANY (
              CASE WHEN (
                DATE_PART('month', ?{$tz}) = 2
                AND DATE_PART('day', ?{$tz}) = 28
                AND DATE_PART('day', ({$endOfMonth})) = 28
              )
              THEN
                ARRAY[28, 29]
              ELSE
                ARRAY[DATE_PART('day', ?{$tz})]
              END
            )
            SQL;
    }

    private function planBillChargesMonthly(): string
    {
        return "plans.bill_charges_monthly = 't'";
    }

    private function planBillFixedChargesMonthlyOnly(): string
    {
        return "plans.bill_fixed_charges_monthly = 't' AND (plans.bill_charges_monthly = 'f' OR plans.bill_charges_monthly IS NULL)";
    }

    private function endOfMonth(): string
    {
        return "(DATE_TRUNC('month', ?{$this->tz()}) + INTERVAL '1 month - 1 day')::date";
    }
}
