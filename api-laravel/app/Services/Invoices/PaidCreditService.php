<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use App\Services\Fees\PaidCreditService as PaidCreditFeeService;

/**
 * Port of Rails' Invoices::PaidCreditService
 * (app/services/invoices/paid_credit_service.rb) — bills a purchased wallet
 * transaction: a `credit` invoice carrying the wallet transaction's fee,
 * finalized right away.
 *
 * TODO(port): invoice custom sections, SegmentTrack + activity logs,
 * GenerateDocumentsJob, integration syncs, and the payment creation
 * (Invoices::Payments::CreateService — payment providers slice).
 */
class PaidCreditService extends BaseService
{
    public function __construct(
        private readonly WalletTransaction $walletTransaction,
        private readonly mixed $timestamp,
        private readonly ?Invoice $invoice = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice');

        $customer = $this->walletTransaction->wallet->customer;

        // NOTE: on a retry, an already-persisted generating invoice is reused
        // instead of creating a second one.
        $invoice = $this->invoice ?? $this->createGeneratingInvoice($customer);
        $result->invoice = $invoice;

        $this->walletTransaction->invoice_id = $invoice->id;
        $this->walletTransaction->save();

        DB::transaction(function () use ($invoice, $customer): void {
            PaidCreditFeeService::call(
                invoice: $invoice,
                walletTransaction: $this->walletTransaction,
                customer: $customer,
            )->raiseIfError();

            $this->computeAmounts($invoice);

            // TODO(port): Invoices::ApplyInvoiceCustomSectionsService.

            if ($this->premium() && (bool) $this->walletTransaction->invoice_requires_successful_payment) {
                // Rails: invoice.open!
                $invoice->status = \App\Enums\InvoiceStatus::Open->value;
                $invoice->save();
            } else {
                FinalizeService::callBang(invoice: $invoice);
            }
        });

        $invoice->refresh();

        // Rails: invoice.paid_credit_added webhook + SegmentTrack + documents
        // + integrations + payment creation — TODO(port) (the invoice.*
        // webhook services beyond created/drafted/generated are later slices;
        // payments arrive with the providers slice).

        return $result;
    }

    private function createGeneratingInvoice(Customer $customer): Invoice
    {
        $invoiceResult = CreateGeneratingService::call(
            customer: $customer,
            invoiceType: InvoiceType::Credit,
            currency: (string) $this->walletTransaction->wallet->currency,
            datetime: \App\Support\Utils\Datetime::parseIso8601($this->timestamp) ?? now(),
            billingEntity: $this->walletTransaction->billingEntity
                ?? $this->walletTransaction->wallet->billingEntity
                ?? $customer->billingEntity,
            purchaseOrderNumber: $this->walletTransaction->purchase_order_number
                ?? $this->walletTransaction->wallet->purchase_order_number,
        );

        return $invoiceResult->raiseIfError()->invoice;
    }

    private function computeAmounts(Invoice $invoice): void
    {
        $fees = $invoice->fees()->get(['amount_cents', 'taxes_amount_cents']);

        $invoice->currency = (string) $this->walletTransaction->wallet->currency;
        $invoice->fees_amount_cents = (int) $fees->sum('amount_cents');
        $invoice->sub_total_excluding_taxes_amount_cents = (int) $invoice->fees_amount_cents;
        $invoice->taxes_amount_cents = (int) $fees->sum('taxes_amount_cents');
        $invoice->sub_total_including_taxes_amount_cents =
            (int) $invoice->sub_total_excluding_taxes_amount_cents + (int) $invoice->taxes_amount_cents;
        $invoice->total_amount_cents = (int) $invoice->sub_total_including_taxes_amount_cents;
        $invoice->save();
    }
}
