<?php

declare(strict_types=1);

namespace App\Services\Wallets;

use App\Support\MoneyMath;
use App\Enums\WalletStatus;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionSource;
use App\Models\RecurringTransactionRule;
use App\Enums\RecurringTransactionTrigger;
use App\Jobs\WalletTransactions\CreateJob;
use App\Enums\RecurringTransactionInterval;
use App\Enums\RecurringTransactionRuleStatus;

/**
 * Port of Rails' Wallets::CreateIntervalWalletTransactionsService
 * (app/services/wallets/create_interval_wallet_transactions_service.rb) —
 * the daily clock job that finds every interval-triggered recurring
 * transaction rule hitting its anniversary today (in the customer's
 * timezone) and enqueues a WalletTransactions::CreateJob per rule.
 *
 * The anniversary SQL is Postgres-specific and ported verbatim (same
 * pattern as Subscriptions::OrganizationBillingService's billing-day SQL).
 * Rails routes it through the `:direct` role to bypass the RDS Proxy
 * 16 KB statement limit — not applicable to the port's connection setup.
 */
class CreateIntervalWalletTransactionsService extends BaseService
{
    public function execute(): BaseResult
    {
        $today = Carbon::now();

        $sql = <<<'SQL'
            WITH
              pending_recurring_rules AS (
                -- Anniversary rules
                (%WEEKLY_ANNIVERSARY%)
                UNION
                (%MONTHLY_ANNIVERSARY%)
                UNION
                (%QUARTERLY_ANNIVERSARY%)
                UNION
                (%SEMIANNUAL_ANNIVERSARY%)
                UNION
                (%YEARLY_ANNIVERSARY%)
              ),
              -- Filter wallets which rules are already applied today (in customer's applicable timezone)
              already_applied_today AS (%ALREADY_APPLIED_TODAY%)
            SELECT DISTINCT(recurring_transaction_rules.*)
            FROM recurring_transaction_rules
              INNER JOIN pending_recurring_rules ON pending_recurring_rules.rule_id = recurring_transaction_rules.id
              INNER JOIN wallets ON wallets.id = recurring_transaction_rules.wallet_id
              INNER JOIN customers ON customers.id = wallets.customer_id
              INNER JOIN billing_entities ON billing_entities.id = customers.billing_entity_id
              LEFT JOIN already_applied_today ON already_applied_today.wallet_id = wallets.id
            WHERE
              -- Exclude top-ups already applied today
              already_applied_today.top_up_count IS NULL
              -- Do not take into account wallets that are created today
              AND DATE(wallets.created_at%AT%) != DATE(:today%AT%)
            GROUP BY recurring_transaction_rules.id
            SQL;

        $replacements = [
            '%WEEKLY_ANNIVERSARY%' => $this->baseRecurringTransactionRuleScope(
                interval: RecurringTransactionInterval::Weekly,
                conditions: [$this->weeklyCondition()],
            ),
            '%MONTHLY_ANNIVERSARY%' => $this->baseRecurringTransactionRuleScope(
                interval: RecurringTransactionInterval::Monthly,
                conditions: [$this->dayOfMonthAnniversaryCondition()],
            ),
            '%QUARTERLY_ANNIVERSARY%' => $this->baseRecurringTransactionRuleScope(
                interval: RecurringTransactionInterval::Quarterly,
                conditions: [$this->quarterlyMonthCondition(), $this->dayOfMonthAnniversaryCondition()],
            ),
            '%SEMIANNUAL_ANNIVERSARY%' => $this->baseRecurringTransactionRuleScope(
                interval: RecurringTransactionInterval::Semiannual,
                conditions: [$this->semiannualMonthCondition(), $this->dayOfMonthAnniversaryCondition()],
            ),
            '%YEARLY_ANNIVERSARY%' => $this->baseRecurringTransactionRuleScope(
                interval: RecurringTransactionInterval::Yearly,
                conditions: [$this->yearlyMonthCondition(), $this->yearlyDayCondition()],
            ),
            '%ALREADY_APPLIED_TODAY%' => $this->alreadyAppliedToday(),
            '%AT%' => self::atTimeZone(),
            '%AT_CUS%' => self::atTimeZone('cus', 'billing_entities'),
        ];

        $sql = str_replace(array_keys($replacements), array_values($replacements), $sql);

        $rows = DB::select($sql, ['today' => $today->toDateTimeString()]);

        $ruleIds = collect($rows)->pluck('id')->unique()->values()->all();

        RecurringTransactionRule::query()
            ->with('wallet')
            ->findMany($ruleIds)
            ->each(fn (RecurringTransactionRule $rule) => $this->createRuleTransaction($rule));

        return static::makeResult();
    }

    /**
     * Port of Utils::Timezone.at_time_zone_sql — the customer's timezone
     * with billing-entity fallback (same helper as
     * Organizations\BillingService).
     */
    private static function atTimeZone(string $customer = 'customers', string $billingEntity = 'billing_entities'): string
    {
        return "::timestamptz AT TIME ZONE COALESCE({$customer}.timezone, {$billingEntity}.timezone, 'UTC')";
    }

    private function createRuleTransaction(RecurringTransactionRule $rule): void
    {
        $wallet = $rule->wallet;

        $ongoingBalance = (string) $wallet->credits_ongoing_balance;
        $paidCredits = $rule->computePaidCredits(ongoingBalance: $ongoingBalance);
        $grantedCredits = $rule->computeGrantedCredits();

        if ($rule->isTarget()
            && bccomp($paidCredits, '0', 5) === 0
            && bccomp($grantedCredits, '0', 5) === 0) {
            return;
        }

        $params = [
            'wallet_id' => $wallet->id,
            'paid_credits' => MoneyMath::toF($paidCredits),
            'granted_credits' => MoneyMath::toF($grantedCredits),
            'source' => WalletTransactionSource::Interval->label(),
            'invoice_requires_successful_payment' => (bool) $rule->invoice_requires_successful_payment,
            'metadata' => $rule->transaction_metadata,
            'name' => $rule->transaction_name,
            'ignore_paid_top_up_limits' => $rule->isTarget() || (bool) $rule->ignore_paid_top_up_limits,
            'purchase_order_number' => $rule->resolvedPurchaseOrderNumber(),
        ];

        $sectionParams = $rule->invoiceCustomSectionParams();

        if ($sectionParams !== null) {
            $params['invoice_custom_section'] = $sectionParams;
        }

        dispatch(new CreateJob(
            organizationId: (string) $wallet->organization_id,
            params: $params,
        ));
    }

    /**
     * Rails: `base_recurring_transaction_rule_scope` — the wallet/rule
     * filters shared by every anniversary branch.
     *
     * @param  list<string>  $conditions
     */
    private function baseRecurringTransactionRuleScope(RecurringTransactionInterval $interval, array $conditions): string
    {
        $expirationGuard = now()->utc()->format('Y-m-d H:i:s');
        $walletActive = WalletStatus::Active->value;
        $ruleActive = RecurringTransactionRuleStatus::Active->value;
        $intervalTrigger = RecurringTransactionTrigger::Interval->value;
        $intervalValue = $interval->value;
        $walletStartedAt = $this->walletStartedAt();
        $conditionsSql = implode(' AND ', $conditions);

        return <<<SQL
            SELECT recurring_transaction_rules.id AS rule_id
            FROM recurring_transaction_rules
              INNER JOIN wallets ON wallets.id = recurring_transaction_rules.wallet_id
              INNER JOIN customers ON customers.id = wallets.customer_id
              INNER JOIN billing_entities ON billing_entities.id = customers.billing_entity_id
            WHERE wallets.status = {$walletActive}
              AND recurring_transaction_rules.status = {$ruleActive}
              AND recurring_transaction_rules.trigger = {$intervalTrigger}
              AND recurring_transaction_rules.interval = {$intervalValue}
              AND {$walletStartedAt} <= :today
              AND (recurring_transaction_rules.expiration_at IS NULL
               OR recurring_transaction_rules.expiration_at > '{$expirationGuard}')
              AND {$conditionsSql}
            GROUP BY recurring_transaction_rules.id
            SQL;
    }

    /** Rails: `weekly_anniversary`'s day condition — same ISO weekday. */
    private function weeklyCondition(): string
    {
        return <<<SQL
            EXTRACT(ISODOW FROM ({$this->walletStartedAt()})) =
            EXTRACT(ISODOW FROM (:today%AT%))
            SQL;
    }

    /**
     * Rails: the monthly/quarterly/semiannual day condition — the wallet's
     * day of month, widened to every day up to 31 on a short month's last
     * day.
     */
    private function dayOfMonthAnniversaryCondition(): string
    {
        return <<<SQL
            DATE_PART('day', ({$this->walletStartedAt()})) = ANY (
              -- Check if today is the last day of the month
              CASE WHEN DATE_PART('day', ({$this->endOfMonth()})) = DATE_PART('day', :today%AT%)
              THEN
                -- If so and if it counts less than 31 days, we need to take all days up to 31 into account
                (SELECT ARRAY(SELECT generate_series(DATE_PART('day', :today%AT%)::integer, 31)))
              ELSE
                -- Otherwise, we just need the current day
                (SELECT ARRAY[DATE_PART('day', :today%AT%)])
              END
            )
            SQL;
    }

    /** Rails: `quarterly_anniversary`'s month condition. */
    private function quarterlyMonthCondition(): string
    {
        return <<<SQL
            (
              -- We need to avoid zero and instead of it use 12. E.g.: (3 + 9) % 12 = 0 -> 12
              CASE WHEN MOD(CAST(DATE_PART('month', ({$this->walletStartedAt()})) AS INTEGER), 3) = 0
              THEN
                (DATE_PART('month', :today%AT%) IN (3, 6, 9, 12))
              ELSE (
                DATE_PART('month', ({$this->walletStartedAt()})) = DATE_PART('month', :today%AT%)
                  OR MOD(CAST(DATE_PART('month', ({$this->walletStartedAt()})) + 3 AS INTEGER), 12) = DATE_PART('month', :today%AT%)
                  OR MOD(CAST(DATE_PART('month', ({$this->walletStartedAt()})) + 6 AS INTEGER), 12) = DATE_PART('month', :today%AT%)
                  OR MOD(CAST(DATE_PART('month', ({$this->walletStartedAt()})) + 9 AS INTEGER), 12) = DATE_PART('month', :today%AT%)
              )
              END
            )
            SQL;
    }

    /** Rails: `semiannual_anniversary`'s month condition. */
    private function semiannualMonthCondition(): string
    {
        return <<<SQL
            (
              -- We need to avoid zero and instead of it use 12. E.g.: (3 + 9) % 12 = 0 -> 12
              CASE WHEN MOD(CAST(DATE_PART('month', ({$this->walletStartedAt()})) AS INTEGER), 6) = 0
              THEN
                (DATE_PART('month', :today%AT%) IN (6, 12))
              ELSE (
                DATE_PART('month', ({$this->walletStartedAt()})) = DATE_PART('month', :today%AT%)
                  OR MOD(CAST(DATE_PART('month', ({$this->walletStartedAt()})) + 6 AS INTEGER), 12) = DATE_PART('month', :today%AT%)
              )
              END
            )
            SQL;
    }

    /** Rails: `yearly_anniversary`'s month condition. */
    private function yearlyMonthCondition(): string
    {
        return <<<SQL
            -- Ensure we are on the billing month
            DATE_PART('month', ({$this->walletStartedAt()})) = DATE_PART('month', :today%AT%)
            SQL;
    }

    /** Rails: `yearly_anniversary`'s day condition — Feb 28 counts for the 29th in non-leap years. */
    private function yearlyDayCondition(): string
    {
        return <<<SQL
            -- Check if we are not in a leap year when today is february the 28th
            DATE_PART('day', ({$this->walletStartedAt()})) = ANY (
              CASE WHEN (
                DATE_PART('month', :today%AT%) = 2
                AND DATE_PART('day', :today%AT%) = 28
                AND DATE_PART('day', ({$this->endOfMonth()})) = 28
              )
              THEN
                -- If not a leap year, we have to tale february the 29th into account
                ARRAY[28, 29]
              ELSE
                -- Otherwise, we just need the current day
                ARRAY[DATE_PART('day', :today%AT%)]
              END
            )
            SQL;
    }

    /** Rails: `end_of_month`. */
    private function endOfMonth(): string
    {
        return "(DATE_TRUNC('month', :today%AT%) + INTERVAL '1 month - 1 day')::date";
    }

    /** Rails: `wallet_started_at`. */
    private function walletStartedAt(): string
    {
        return <<<'SQL'
            COALESCE(
              recurring_transaction_rules.started_at%AT%,
              wallets.created_at%AT%
            )
            SQL;
    }

    /** Rails: `already_applied_today` — one interval top-up per wallet per (customer-tz) day. */
    private function alreadyAppliedToday(): string
    {
        $source = WalletTransactionSource::Interval->value;
        $inbound = WalletTransactionType::Inbound->value;

        return <<<SQL
            SELECT
              wallet_transactions.wallet_id,
              COUNT(wallet_transactions.id) AS top_up_count
            FROM wallet_transactions
              INNER JOIN wallets AS wal ON wallet_transactions.wallet_id = wal.id
              INNER JOIN customers AS cus ON wal.customer_id = cus.id
              INNER JOIN billing_entities ON cus.billing_entity_id = billing_entities.id
            WHERE wallet_transactions.source = {$source}
              AND wallet_transactions.transaction_type = {$inbound}
              AND DATE(
                (wallet_transactions.created_at)%AT_CUS%
              ) = DATE(:today%AT_CUS%)
            GROUP BY wallet_transactions.wallet_id
            SQL;
    }
}
