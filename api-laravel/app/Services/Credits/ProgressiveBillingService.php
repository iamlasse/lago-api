<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\Credit;
use App\Models\Invoice;
use App\Support\MoneyMath;
use App\Services\BaseResult;

/**
 * Port of Rails' Credits::ProgressiveBillingService
 * (app/services/credits/progressive_billing_service.rb) — credits the
 * progressive-billing amounts already invoiced during the period from the
 * final subscription invoice.
 *
 * Subscriptions::ProgressiveBilledAmount — WIRED (usage-monitoring slice).
 * TODO(port): CreditNotes::CreateFromProgressiveBillingInvoice (when the
 * credit amount exceeds the period's charge total, Rails first creates a
 * credit note from the progressive-billing invoice; the port clamps the
 * credit to the charges total for now).
 */
class ProgressiveBillingService extends \App\Services\BaseService
{
    public function __construct(private readonly Invoice $invoice) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credits');
        $result->credits = [];

        foreach ($this->invoice->invoiceSubscriptions as $invoiceSubscription) {
            $subscription = $invoiceSubscription->subscription;

            // TODO(port): Subscriptions::ProgressiveBilledAmount.call!
            $progressiveBillingInvoice = $this->progressiveBillingInvoice($subscription, $invoiceSubscription->charges_from_datetime);

            if ($progressiveBillingInvoice === null) {
                continue;
            }

            $chargeIds = $progressiveBillingInvoice->fees()
                ->charge()
                ->pluck('charge_id');

            $totalChargesAmount = (int) $this->invoice->fees()
                ->charge()
                ->where('subscription_id', $subscription->id)
                ->whereIn('charge_id', $chargeIds)
                ->sum('amount_cents');

            // Don't be tempted to calculate the credit amount yourself, you
            // have to use the result from this service.
            $amountToCredit = $this->toCreditAmount($subscription, $progressiveBillingInvoice);

            if ($amountToCredit > $totalChargesAmount) {
                // TODO(port): CreditNotes::CreateFromProgressiveBillingInvoice.
                $amountToCredit = $totalChargesAmount;
            }

            if ($amountToCredit > 0) {
                $credit = Credit::query()->create([
                    'organization_id' => $this->invoice->organization_id,
                    'invoice_id' => $this->invoice->id,
                    'progressive_billing_invoice_id' => $progressiveBillingInvoice->id,
                    'amount_cents' => $amountToCredit,
                    'amount_currency' => $this->invoice->currency,
                    'before_taxes' => true,
                ]);

                $this->applyCreditToFees($progressiveBillingInvoice);

                $this->invoice->sub_total_excluding_taxes_amount_cents =
                    (int) $this->invoice->sub_total_excluding_taxes_amount_cents - $credit->amount_cents;
                $this->invoice->progressive_billing_credit_amount_cents =
                    (int) $this->invoice->progressive_billing_credit_amount_cents + $credit->amount_cents;

                $result->credits = array_merge($result->credits ?? [], [$credit]);
            }
        }

        return $result;
    }

    /**
     * Subscriptions::ProgressiveBilledAmount — WIRED (usage-monitoring
     * slice): the last progressive-billing invoice of the subscription
     * covering the period.
     */
    private function progressiveBillingInvoice(object $subscription, mixed $chargesFromDatetime): ?Invoice
    {
        $result = \App\Services\Subscriptions\ProgressiveBilledAmount::call(
            subscription: $subscription,
            timestamp: \Carbon\CarbonImmutable::parse($chargesFromDatetime),
        );

        return $result->progressive_billing_invoice;
    }

    /** Subscriptions::ProgressiveBilledAmount#to_credit_amount — WIRED. */
    private function toCreditAmount(object $subscription, Invoice $progressiveBillingInvoice): int
    {
        $result = \App\Services\Subscriptions\ProgressiveBilledAmount::call(
            subscription: $subscription,
            timestamp: \Carbon\CarbonImmutable::parse($progressiveBillingInvoice->invoiceSubscriptions->first()?->charges_from_datetime ?? $progressiveBillingInvoice->created_at),
        );

        return $result->to_credit_amount;
    }

    private function applyCreditToFees(Invoice $progressiveBillingInvoice): void
    {
        // Use the loaded association so the credit stays visible to the caller's in-memory fees.
        $invoiceFees = collect($this->invoice->fees)->filter(fn ($fee) => $fee->isCharge());

        $progressiveBillingInvoice->fees()->charge()->get()->each(function ($progressiveFee) use ($invoiceFees): void {
            $fee = $invoiceFees->first(
                fn ($f) => $f->charge_id === $progressiveFee->charge_id
                    && $f->charge_filter_id === $progressiveFee->charge_filter_id
                    && $f->grouped_by === $progressiveFee->grouped_by,
            );

            if ($fee === null) {
                return;
            }

            $fee->precise_coupons_amount_cents = MoneyMath::add(
                (string) $fee->precise_coupons_amount_cents,
                (string) $progressiveFee->amount_cents,
            );

            if ((int) $fee->amount_cents < MoneyMath::round((string) $fee->precise_coupons_amount_cents)) {
                $fee->precise_coupons_amount_cents = (string) $fee->amount_cents;
            }

            $fee->save();
        });
    }
}
