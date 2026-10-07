<?php

declare(strict_types=1);

namespace App\Services\DunningCampaigns;

use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\DunningCampaignThreshold;
use App\Jobs\DunningCampaigns\ProcessAttemptJob;

/**
 * Port of Rails' DunningCampaigns::ProcessCustomerService
 * (app/services/dunning_campaigns/process_customer_service.rb) — the
 * per-customer pass of the dunning loop:
 *
 * 1. gates: organization.auto_dunning_enabled?, customer not excluded, an
 *    applicable campaign (explicit, else the billing entity's), and the
 *    days_between_attempts spacing satisfied;
 * 2. the thresholds still reachable this round (per overdue currency, below
 *    max_attempts, amount_cents <= overdue balance) — none means done;
 * 3. the per-currency attempt counters increment and the attempt timestamp
 *    is stamped;
 * 4. a ProcessAttemptJob per overdue billing entity per currency;
 * 5. "dunning_campaign.finished" when every dunned currency reached
 *    max_attempts.
 */
class ProcessCustomerService extends BaseService
{
    public function __construct(
        private readonly object $customer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        /** @var Customer $customer */
        $customer = $this->customer;

        $organization = $customer->organization;

        if ($organization === null || ! $organization->autoDunningEnabled()) {
            return $result;
        }

        if ($customer->exclude_from_dunning_campaign) {
            return $result;
        }

        // Rails: customer.applied_dunning_campaign ||
        //   billing_entity.applied_dunning_campaign.
        $campaign = $customer->appliedDunningCampaign
            ?? $customer->billingEntity?->appliedDunningCampaign;

        if ($campaign === null) {
            return $result;
        }

        if (! $this->daysBetweenAttemptsSatisfied($customer, $campaign)) {
            return $result;
        }

        $thresholds = $this->applicableThresholds($customer, $campaign);

        if ($thresholds === []) {
            return $result;
        }

        $this->incrementPerCurrencyAttempts($customer, $thresholds);

        foreach ($thresholds as $threshold) {
            foreach ($this->overdueBillingEntitiesFor($customer, $organization, $threshold->currency) as $billingEntity) {
                dispatch(new \App\Jobs\DunningCampaigns\ProcessAttemptJob(customerId: $customer->id, dunningCampaignThresholdId: $threshold->id, billingEntityId: $billingEntity->id));
            }
        }

        if ($this->allDunnedCurrenciesMaxAttemptsReached($customer, $campaign)) {
            SendWebhookJob::performLater(
                'dunning_campaign.finished',
                $customer,
                ['dunning_campaign_code' => $campaign->code],
            );
        }

        return $result;
    }

    /** Rails: days_between_attempts_satisfied?. */
    private function daysBetweenAttemptsSatisfied(Customer $customer, object $campaign): bool
    {
        if ($customer->last_dunning_campaign_attempt_at === null) {
            return true;
        }

        $nextAttemptDate = $customer->last_dunning_campaign_attempt_at
            ->copy()
            ->addDays((int) $campaign->days_between_attempts);

        return now()->gt($nextAttemptDate);
    }

    /**
     * Rails: applicable_dunning_campaign_thresholds — per overdue currency,
     * skip when max_attempts is already reached, then the cheapest threshold
     * the overdue balance reaches (find_by("amount_cents <= ?")). At most
     * one threshold per currency.
     *
     * @return list<DunningCampaignThreshold>
     */
    private function applicableThresholds(Customer $customer, object $campaign): array
    {
        $attempts = (array) ($customer->dunning_currency_attempts ?? []);
        $thresholds = [];

        foreach ($customer->overdueBalances() as $currency => $amountCents) {
            if ((int) ($attempts[$currency] ?? 0) >= (int) $campaign->max_attempts) {
                continue;
            }

            $threshold = $campaign->thresholds()
                ->where('currency', $currency)
                ->where('amount_cents', '<=', (int) $amountCents)
                ->orderBy('amount_cents')
                ->first();

            if ($threshold !== null) {
                $thresholds[] = $threshold;
            }
        }

        return $thresholds;
    }

    /**
     * Rails: overdue_billing_entities_for — the billing entities of the
     * customer's payment-overdue, ready-for-processing, non-self-billed
     * invoices in the currency.
     *
     * @return \Illuminate\Support\Collection
     */
    private function overdueBillingEntitiesFor(Customer $customer, object $organization, string $currency)
    {
        $billingEntityIds = $customer->invoices()
            ->where('invoices.self_billed', false)
            ->where('invoices.payment_overdue', true)
            ->where('invoices.ready_for_payment_processing', true)
            ->where('invoices.currency', $currency)
            ->distinct()
            ->select('invoices.billing_entity_id')
            ->pluck('billing_entity_id');

        return $organization->billingEntities()->whereIn('id', $billingEntityIds)->get();
    }

    /** Rails: increment_per_currency_attempts — save! the new bookkeeping. */
    private function incrementPerCurrencyAttempts(Customer $customer, array $thresholds): void
    {
        $attempts = (array) ($customer->dunning_currency_attempts ?? []);

        foreach ($thresholds as $threshold) {
            $attempts[$threshold->currency] = (int) ($attempts[$threshold->currency] ?? 0) + 1;
        }

        $customer->dunning_currency_attempts = $attempts;
        $customer->last_dunning_campaign_attempt_at = now();
        $customer->save();
    }

    /** Rails: all_dunned_currencies_max_attempts_reached?. */
    private function allDunnedCurrenciesMaxAttemptsReached(Customer $customer, object $campaign): bool
    {
        $attempts = (array) ($customer->dunning_currency_attempts ?? []);

        if ($attempts === []) {
            return false;
        }

        foreach ($attempts as $count) {
            if ((int) $count < (int) $campaign->max_attempts) {
                return false;
            }
        }

        return true;
    }
}
