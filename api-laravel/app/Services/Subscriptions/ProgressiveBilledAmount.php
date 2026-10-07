<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Invoice;
use Carbon\CarbonInterface;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Models\CreditNoteItem;
use App\Models\InvoiceSubscription;

/**
 * Port of Rails' Subscriptions::ProgressiveBilledAmount
 * (app/services/subscriptions/progressive_billed_amount.rb) — how much the
 * subscription has already been progressively billed in the current period,
 * and how that last invoice splits into amounts still to invoice vs. credit.
 */
class ProgressiveBilledAmount extends \App\Services\BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly CarbonInterface|int|null $timestamp = null,
        private readonly bool $includeGeneratingInvoices = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of(
            'progressive_billed_amount',
            'progressive_billing_invoice',
            'to_credit_amount',
            'to_invoice_amount',
            'total_billed_amount_cents',
            'invoice_subscriptions',
        );

        $subscription = $this->subscription;
        $timestamp = $this->timestamp ?? now();

        if (! $timestamp instanceof CarbonInterface) {
            $timestamp = \Carbon\CarbonImmutable::parse($timestamp);
        }

        $result->progressive_billed_amount = 0;
        $result->total_billed_amount_cents = 0;
        $result->progressive_billing_invoice = null;
        $result->to_credit_amount = 0;
        $result->to_invoice_amount = 0;

        // Note: we might be refreshing balance while applying credits on a
        // generating invoice; in that case this invoice should be included.
        $statuses = $this->includeGeneratingInvoices
            ? [\App\Enums\InvoiceStatus::Finalized->value, \App\Enums\InvoiceStatus::Failed->value, \App\Enums\InvoiceStatus::Generating->value]
            : [\App\Enums\InvoiceStatus::Finalized->value, \App\Enums\InvoiceStatus::Failed->value];

        $invoiceSubscriptions = InvoiceSubscription::query()
            ->where('charges_to_datetime', '>', $timestamp)
            ->where('charges_from_datetime', '<=', $timestamp)
            ->join('invoices', 'invoices.id', '=', 'invoice_subscriptions.invoice_id')
            ->where('invoices.invoice_type', \App\Enums\InvoiceType::ProgressiveBilling->value)
            ->whereIn('invoices.status', $statuses)
            ->where('invoice_subscriptions.subscription_id', $subscription->id)
            ->latest('invoices.issuing_date')
            ->latest('invoices.created_at')
            ->select('invoice_subscriptions.*')
            ->get();

        $result->invoice_subscriptions = $invoiceSubscriptions;

        if ($invoiceSubscriptions->isEmpty()) {
            return $result;
        }

        // Note: an included generating invoice won't have values, so we iterate
        // through the fees; progressively billed fees include previously
        // progressively paid fees, so sub_total_excluding_taxes + taxes from
        // the fees give the exact billed amount.
        $totalBilledAmountCents = $invoiceSubscriptions->sum(function (InvoiceSubscription $invoiceSubscription): int {
            $fees = $invoiceSubscription->invoice->fees;

            return (int) $fees->sum('taxes_amount_cents')
                + (int) $fees->sum('sub_total_excluding_taxes_amount_cents');
        });

        $result->total_billed_amount_cents = $totalBilledAmountCents;

        $invoiceSubscription = $invoiceSubscriptions->first();
        $invoice = $invoiceSubscription->invoice;

        $result->progressive_billing_invoice = $invoice;
        $result->progressive_billed_amount = (int) $invoice->fees_amount_cents;

        $alreadyAppliedAmount = (int) \App\Models\Credit::query()
            ->where('progressive_billing_invoice_id', $invoice->id)
            // Rails: Credit.active — the host invoice is not voided/closed/deleted.
            ->join('invoices', 'invoices.id', '=', 'credits.invoice_id')
            ->whereNotIn('invoices.status', [
                \App\Enums\InvoiceStatus::Voided->value,
                \App\Enums\InvoiceStatus::Closed->value,
                \App\Enums\InvoiceStatus::Deleted->value,
            ])
            ->sum('credits.amount_cents');

        // Invoice offsets reverse gross usage; credit note items also store gross amounts.
        $toInvoiceAmount = (int) $invoice->fees_amount_cents - $alreadyAppliedAmount;
        $toInvoiceAmount -= (int) CreditNoteItem::query()
            ->whereIn('credit_note_id', $invoice->creditNotes()->select('id'))
            ->sum('amount_cents');

        $result->to_invoice_amount = max(0, $toInvoiceAmount);

        $toCreditAmount = (int) $invoice->fees_amount_cents;
        $toCreditAmount -= (int) $invoice->coupons_amount_cents;
        $toCreditAmount -= $alreadyAppliedAmount;
        $toCreditAmount -= (int) $invoice->creditNotes()
            ->whereIn('credit_status', [
                \App\Enums\CreditNoteCreditStatus::Available->value,
                \App\Enums\CreditNoteCreditStatus::Consumed->value,
            ])
            ->sum('credit_amount_cents');

        // If for some reason this goes below zero, it should be zero.
        $result->to_credit_amount = max(0, $toCreditAmount);

        return $result;
    }
}
