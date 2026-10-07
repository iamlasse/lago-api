<?php

declare(strict_types=1);

namespace App\Services\DunningCampaigns;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Jobs\DunningCampaigns\ProcessCustomerJob;

/**
 * Port of Rails' DunningCampaigns::BulkProcessService
 * (app/services/dunning_campaigns/bulk_process_service.rb) — the daily
 * Clock fan-out: every non-excluded customer of an auto-dunning
 * organization carrying a payment-overdue, non-self-billed invoice gets a
 * ProcessCustomerJob.
 *
 * Rails gates the whole pass on License.premium? (the per-organization
 * auto_dunning_enabled? check happens per customer in
 * ProcessCustomerService).
 */
class BulkProcessService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult();

        if (! $this->premium()) {
            return $result;
        }

        // Rails: Customer.joins(:organization).where(exclude...,
        // organizations.premium_integrations @> ['auto_dunning'], id:
        // overdue_invoice_customers).select(:id).find_each.
        Customer::query()
            ->whereIn('customers.id', function ($query): void {
                $query->select('invoices.customer_id')
                    ->from('invoices')
                    ->where('invoices.payment_overdue', true)
                    ->where('invoices.self_billed', false);
            })
            ->where('customers.exclude_from_dunning_campaign', false)
            ->whereIn('customers.organization_id', function ($query): void {
                // Rails: "organizations.premium_integrations @>
                // ARRAY[?]::varchar[]" — PostgresArray text contains the
                // quoted element.
                $query->select('organizations.id')
                    ->from('organizations')
                    ->where('organizations.premium_integrations', 'like', '%"auto_dunning"%');
            })
            ->select('customers.id')
            ->chunkById(500, function ($customers): void {
                foreach ($customers as $customer) {
                    dispatch(new \App\Jobs\DunningCampaigns\ProcessCustomerJob($customer->id));
                }
            });

        return $result;
    }
}
