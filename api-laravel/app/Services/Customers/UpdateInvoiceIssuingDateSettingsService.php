<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

use function is_array;
use function array_key_exists;

/**
 * Port of Rails' Customers::UpdateInvoiceIssuingDateSettingsService —
 * assigns the issuing-date settings carried by billing_configuration.
 *
 * TODO(port): the draft-invoice issuing_date / expected_finalization_date /
 * payment_due_date recomputation (Invoices::IssuingDateService) and the
 * ready_to_be_finalized FinalizeJob enqueue once the invoice pipeline
 * services exist.
 */
class UpdateInvoiceIssuingDateSettingsService extends BaseService
{
    public function __construct(
        private readonly Customer $customer,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer');
        $customer = $this->customer;

        $billingConfiguration = $this->params['billing_configuration'] ?? [];
        $billingConfiguration = is_array($billingConfiguration) ? $billingConfiguration : [];

        if (array_key_exists('subscription_invoice_issuing_date_anchor', $billingConfiguration)) {
            $customer->subscription_invoice_issuing_date_anchor = $billingConfiguration['subscription_invoice_issuing_date_anchor'];
        }

        if (array_key_exists('subscription_invoice_issuing_date_adjustment', $billingConfiguration)) {
            $customer->subscription_invoice_issuing_date_adjustment = $billingConfiguration['subscription_invoice_issuing_date_adjustment'];
        }

        if ($this->premium() && array_key_exists('invoice_grace_period', $this->params)) {
            $customer->invoice_grace_period = $this->params['invoice_grace_period'];
        }

        if ($this->premium() && array_key_exists('invoice_grace_period', $billingConfiguration)) {
            $customer->invoice_grace_period = $billingConfiguration['invoice_grace_period'];
        }

        DB::transaction(function () use ($customer): void {
            if (! $customer->isDirty()) {
                return;
            }

            $customer->save();

            // TODO(port): update issuing dates on the customer's draft
            // invoices and re-enqueue ready_to_be_finalized invoices.
        });

        $result->customer = $customer;

        return $result;
    }
}
