<?php

declare(strict_types=1);

namespace App\Services\DunningCampaigns;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Models\BillingEntity;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Models\DunningCampaignThreshold;
use App\Services\PaymentRequests\CreateService as PaymentRequestCreateService;

/**
 * Port of Rails' DunningCampaigns::ProcessAttemptService
 * (app/services/dunning_campaigns/process_attempt_service.rb) — one dunning
 * attempt for one customer / threshold / billing entity: the gates (auto
 * dunning enabled, campaign still the applicable one, threshold amount
 * reached by the overdue invoice sum) then the payment request over the
 * overdue invoices of that entity + currency, tagged with the campaign.
 *
 * TODO(port): the payment request email emission (Rails' PaymentRequestMailer
 * enqueued from PaymentRequests::CreateService's email slice) — the
 * email-at-threshold notification stays unwired until that mailer lands.
 */
class ProcessAttemptService extends BaseService
{
    public function __construct(
        private readonly object $customer,
        private readonly object $dunningCampaignThreshold,
        private readonly object $billingEntity,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer', 'payment_request');

        /** @var Customer $customer */
        $customer = $this->customer;
        /** @var DunningCampaignThreshold $threshold */
        $threshold = $this->dunningCampaignThreshold;
        /** @var BillingEntity $billingEntity */
        $billingEntity = $this->billingEntity;

        $organization = $customer->organization;
        $campaign = $threshold->dunningCampaign;

        if ($organization === null || ! $organization->autoDunningEnabled()) {
            return $result;
        }

        if (! $this->applicableDunningCampaign($customer, $campaign)) {
            return $result;
        }

        if (! $this->thresholdReached($customer, $threshold)) {
            return $result;
        }

        $overdueInvoiceIds = $this->overdueInvoices($customer, $threshold, $billingEntity)
            ->pluck('id')
            ->all();

        $paymentRequestResult = DB::transaction(function () use ($customer, $organization, $campaign, $overdueInvoiceIds): BaseResult {
            // Rails: PaymentRequests::CreateService.call(...).raise_if_error!
            return PaymentRequestCreateService::callBang(
                organization: $organization,
                params: [
                    'external_customer_id' => $customer->external_id,
                    'lago_invoice_ids' => $overdueInvoiceIds,
                ],
                dunningCampaign: $campaign,
            );
        });

        $result->customer = $customer;
        $result->payment_request = $paymentRequestResult->payment_request;

        return $result;
    }

    /**
     * Rails: applicable_dunning_campaign? — the campaign resolved at the
     * customer's own billing entity (not the invoice's) is either the
     * explicitly applied one or the fallback.
     */
    private function applicableDunningCampaign(Customer $customer, object $campaign): bool
    {
        if ($customer->exclude_from_dunning_campaign) {
            return false;
        }

        $customCampaign = $customer->appliedDunningCampaign;
        $defaultCampaign = $customer->billingEntity?->appliedDunningCampaign;

        return $customCampaign?->id === $campaign->id
            || ($customCampaign === null && $defaultCampaign?->id === $campaign->id);
    }

    /** Rails: dunning_campaign_threshold_reached? — overdue sum >= threshold. */
    private function thresholdReached(Customer $customer, object $threshold): bool
    {
        return (int) $this->overdueInvoicesInCurrency($customer, $threshold)
            ->sum('total_amount_cents') >= (int) $threshold->amount_cents;
    }

    /** Rails: overdue_invoices_in_currency. */
    private function overdueInvoicesInCurrency(Customer $customer, object $threshold)
    {
        return $customer->invoices()
            ->where('invoices.self_billed', false)
            ->where('invoices.payment_overdue', true)
            ->where('invoices.ready_for_payment_processing', true)
            ->where('invoices.currency', $threshold->currency);
    }

    /** Rails: overdue_invoices — the entity-scoped subset. */
    private function overdueInvoices(Customer $customer, object $threshold, object $billingEntity)
    {
        return $this->overdueInvoicesInCurrency($customer, $threshold)
            ->where('invoices.billing_entity_id', $billingEntity->id);
    }
}
