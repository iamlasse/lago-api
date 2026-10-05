<?php

declare(strict_types=1);

namespace App\Services\LifetimeUsages;

use App\Models\Invoice;
use App\Services\BaseResult;

/**
 * Port of Rails' LifetimeUsages::FlagRefreshFromInvoiceService
 * (app/services/lifetime_usages/flag_refresh_from_invoice_service.rb) —
 * flags the invoiced-usage recalculation on the lifetime usage of every
 * subscription the invoice bills.
 */
class FlagRefreshFromInvoiceService extends \App\Services\BaseService
{
    public function __construct(private readonly Invoice $invoice)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('lifetime_usages');
        $result->lifetime_usages = [];

        if (! $this->invoice->isSubscription()) {
            return $result;
        }

        if (! $this->shouldFlagRefreshFromInvoice()) {
            return $result;
        }

        foreach ($this->invoice->invoiceSubscriptions as $invoiceSubscription) {
            $subscription = $invoiceSubscription->subscription;

            $lifetimeUsage = $subscription->lifetimeUsage;

            $lifetimeUsage ??= $subscription->buildLifetimeUsage();

            $lifetimeUsage->recalculate_invoiced_usage = true;
            $lifetimeUsage->save();

            $rows = $result->lifetime_usages ?? [];
            $rows[] = $lifetimeUsage;
            $result->lifetime_usages = $rows;
        }

        return $result;
    }

    private function shouldFlagRefreshFromInvoice(): bool
    {
        $organization = $this->invoice->organization;

        if ($organization->lifetimeUsageEnabled()) {
            return true;
        }

        return $this->invoice->invoiceSubscriptions
            ->contains(fn ($invoiceSubscription): bool => $invoiceSubscription->subscription->hasProgressiveBilling());
    }
}
