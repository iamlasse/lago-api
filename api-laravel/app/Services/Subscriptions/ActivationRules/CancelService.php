<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules;

use App\Models\Invoice;
use App\Models\Payment;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Subscriptions\ActivationRules\Payment\EvaluateService as PaymentEvaluateService;

/**
 * Port of Rails' Subscriptions::ActivationRules::CancelService
 * (app/services/subscriptions/activation_rules/cancel_service.rb) — cancels
 * an incomplete (still-gating) subscription with a given rule status
 * (expired / declined / ...), closes the gating invoice and recredits what
 * it consumed.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): PaymentProviders::CancelPaymentJob — payment providers part 2.
 * - TODO(port): CreditNotes::RecreditJob + WalletTransactions::RecreditJob —
 *   the credit-note / wallet recredit job wrappers.
 */
class CancelService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly string $ruleStatus,
        private readonly string $cancellationReason,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription');

        DB::transaction(function () use ($result): void {
            // Rails: subscription.with_lock.
            $locked = Subscription::query()->whereKey($this->subscription->id)->lockForUpdate()->first()
                ?? $this->subscription;

            if ($locked->incomplete()) {
                $this->cancelIncompleteSubscription($locked, $result);
            } else {
                $result->singleValidationFailure('subscription_already_resolved', 'subscription');

                return;
            }

            $result->subscription = $locked;
        });

        $result->subscription ??= $this->subscription;

        return $result;
    }

    // -- Steps ------------------------------------------------------------------------

    protected function cancelIncompleteSubscription(Subscription $subscription, BaseResult $result): void
    {
        $invoice = $this->gatingInvoice($subscription);

        if ($invoice === null) {
            $result->singleValidationFailure('activation_invoice_not_ready', 'subscription');

            return;
        }

        $paymentRule = $subscription->activationRules()->where('type', 'payment')->sole();

        PaymentEvaluateService::callBang(rule: $paymentRule, status: $this->ruleStatus);

        // Locked because Invoices::RetryService reopens a failed invoice under
        // the same lock.
        Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first() ?? $invoice;
        $invoice->status = InvoiceStatus::Closed->value;
        $invoice->save();

        ResolveSubscriptionStatusService::callBang(subscription: $subscription);

        $subscription->cancellation_reason = $this->cancellationReason;
        $subscription->save();

        $this->enqueuePspCancel($invoice);
        $this->enqueueRecreditJobs($invoice);
    }

    /**
     * A tax provider failure leaves the invoice failed rather than open, and
     * it still has to be closed: Invoices::RetryService would otherwise let a
     * merchant reopen it on a subscription that is no longer activating.
     */
    protected function gatingInvoice(Subscription $subscription): ?Invoice
    {
        // Rails: subscription.invoices.subscription.where(status: %i[open failed]).first
        // (select invoices.* — the join's duplicate id column must not
        // override the invoice's.)
        return Invoice::query()
            ->select('invoices.*')
            ->join('invoice_subscriptions', 'invoice_subscriptions.invoice_id', '=', 'invoices.id')
            ->where('invoice_subscriptions.subscription_id', $subscription->id)
            ->where('invoices.invoice_type', InvoiceType::Subscription->value)
            ->whereIn('invoices.status', [InvoiceStatus::Open->value, InvoiceStatus::Failed->value])
            ->oldest('invoices.created_at')
            ->first();
    }

    protected function enqueueRecreditJobs(Invoice $invoice): void
    {
        // Rails: invoice.credits.coupon_kind — the frozen schema has no
        // credit_kind column; coupon credits are the ones carrying an
        // applied_coupon_id.
        $invoice->credits()
            ->whereNotNull('applied_coupon_id')
            ->each(function ($credit): void {
                \App\Services\AppliedCoupons\RecreditService::call(credit: $credit);

                // TODO(port): AppliedCoupons::RecreditJob — the deferred job
                // wrapper (the service runs inline here).
            });

        // TODO(port): invoice.credits.credit_note_kind →
        //   CreditNotes::RecreditJob.perform_after_commit(credit).

        // TODO(port): invoice.wallet_transactions.outbound →
        //   WalletTransactions::RecreditJob.perform_after_commit(wallet_transaction).
    }

    /**
     * A partial unique index allows at most one payment in (pending,
     * processing) per invoice.
     */
    protected function enqueuePspCancel(Invoice $invoice): void
    {
        $payment = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('payable_payment_status', ['pending', 'processing'])
            ->first();

        if ($payment === null) {
            return;
        }

        // TODO(port): PaymentProviders::CancelPaymentJob.perform_after_commit(payment)
        // — payment providers part 2 owns the PSP-side cancel.
    }
}
