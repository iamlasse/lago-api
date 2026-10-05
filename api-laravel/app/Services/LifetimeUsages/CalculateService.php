<?php

declare(strict_types=1);

namespace App\Services\LifetimeUsages;

use App\Services\BaseResult;
use App\Models\LifetimeUsage;
use App\Services\Invoices\CustomerUsageService;

/**
 * Port of Rails' LifetimeUsages::CalculateService
 * (app/services/lifetime_usages/calculate_service.rb) — refreshes the
 * lifetime usage ledger's invoiced and current usage amounts per its
 * recalculate flags.
 */
class CalculateService extends \App\Services\BaseService
{
    public function __construct(
        private readonly LifetimeUsage $lifetimeUsage,
        private readonly mixed $currentUsage = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('lifetime_usage');
        $lifetimeUsage = $this->lifetimeUsage;

        $result->lifetime_usage = $lifetimeUsage;

        // Clear boolean flags without recalculating if the subscription is not active.
        if (! $lifetimeUsage->subscription->active()) {
            $lifetimeUsage->recalculate_current_usage = false;
            $lifetimeUsage->recalculate_invoiced_usage = false;
            $lifetimeUsage->save();

            return $result;
        }

        if ($lifetimeUsage->recalculate_invoiced_usage) {
            $lifetimeUsage->invoiced_usage_amount_cents = $this->calculateInvoicedUsageAmountCents($lifetimeUsage);
            $lifetimeUsage->recalculate_invoiced_usage = false;
            $lifetimeUsage->invoiced_usage_amount_refreshed_at = now();
        }

        $lifetimeUsage->current_usage_amount_cents = $this->calculateCurrentUsageAmountCents($lifetimeUsage);
        $lifetimeUsage->recalculate_current_usage = false;
        $lifetimeUsage->current_usage_amount_refreshed_at = now();

        $lifetimeUsage->save();

        return $result;
    }

    private function calculateInvoicedUsageAmountCents(LifetimeUsage $lifetimeUsage): int
    {
        $subscription = $lifetimeUsage->subscription;
        $organization = $lifetimeUsage->organization;

        $subscriptionIds = $organization->subscriptions()
            ->where('external_id', $subscription->external_id)
            ->where('subscription_at', $subscription->subscription_at)
            ->whereNull('canceled_at')
            ->select('id');

        $invoiceIds = $organization->invoices()
            ->where('invoice_type', \App\Enums\InvoiceType::Subscription->value)
            ->whereIn('status', [
                \App\Enums\InvoiceStatus::Finalized->value,
                \App\Enums\InvoiceStatus::Draft->value,
            ])
            ->join('invoice_subscriptions', 'invoice_subscriptions.invoice_id', '=', 'invoices.id')
            ->whereIn('invoice_subscriptions.subscription_id', $subscriptionIds)
            ->select('invoices.id');

        return (int) \App\Models\Fee::query()
            ->charge()
            ->whereIn('invoice_id', $invoiceIds)
            ->whereIn('subscription_id', $subscriptionIds)
            ->sum('amount_cents');
    }

    private function calculateCurrentUsageAmountCents(LifetimeUsage $lifetimeUsage): int
    {
        $subscription = $lifetimeUsage->subscription;

        if ($this->currentUsage !== null) {
            $usage = $this->currentUsage;
        } else {
            /** @var BaseResult $usageResult */
            $usageResult = CustomerUsageService::call(
                customer: $subscription->customer,
                subscription: $subscription,
                applyTaxes: false,
                withCache: true,
            );

            $usage = $usageResult->raiseIfError()->usage;
        }

        return (int) ($usage->amountCents ?? $usage['amount_cents'] ?? 0);
    }
}
