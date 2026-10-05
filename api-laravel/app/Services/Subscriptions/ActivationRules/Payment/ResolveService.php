<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\ActivationRules\Payment;

use App\Models\Invoice;
use App\Models\Payment;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Invoices\FinalizeService;
use App\Services\Subscriptions\ActivationRules\ResolveSubscriptionStatusService;

/**
 * Port of Rails' Subscriptions::ActivationRules::Payment::ResolveService
 * (app/services/subscriptions/activation_rules/payment/resolve_service.rb) —
 * resolves the payment activation rule from a gating invoice's payment
 * outcome, then finalizes (success) or closes (failure) the invoice and
 * resolves the subscription's status.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): the after_commit side effects — Invoices::GenerateDocumentsJob,
 *   integrations sync jobs, SegmentTrack, PaymentProviders::UpdatePaymentReferenceJob.
 * - TODO(port): recredit jobs on failure —
 *   AppliedCoupons/CreditNotes/WalletTransactions::RecreditJob (the coupon
 *   recredit runs inline; the other wrappers follow their slices).
 */
class ResolveService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly Invoice $invoice,
        private readonly string $paymentStatus, // 'succeeded' | 'failed'
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        DB::transaction(function (): void {
            // Rails: subscription.with_lock.
            Subscription::query()->whereKey($this->subscription->id)->lockForUpdate()->first();

            if ($this->paymentStatus === 'succeeded') {
                $this->handleSuccess();
            } elseif ($this->paymentStatus === 'failed') {
                $this->handleFailure();
            }
        });

        return $result;
    }

    // -- Steps ------------------------------------------------------------------------

    protected function handleSuccess(): void
    {
        if (! $this->gatingStillPending()) {
            return;
        }

        EvaluateService::callBang(rule: $this->paymentRule(), status: 'satisfied');
        FinalizeService::call(invoice: $this->invoice)->raiseIfError();
        ResolveSubscriptionStatusService::callBang(subscription: $this->subscription);

        // Rails after_commit:
        // - SendWebhookJob "invoice.created" + ActivityLog
        // - Invoices::GenerateDocumentsJob / integrations sync jobs
        // - SegmentTrack
        // - PaymentProviders::UpdatePaymentReferenceJob (PSP reference
        //   reconciliation) — payment providers part 2.
        SendWebhookJob::performLater('invoice.created', $this->invoice);
    }

    protected function handleFailure(): void
    {
        if (! $this->gatingStillPending()) {
            return;
        }

        EvaluateService::callBang(rule: $this->paymentRule(), status: 'failed');

        $this->invoice->status = InvoiceStatus::Closed->value;
        $this->invoice->save();

        ResolveSubscriptionStatusService::callBang(subscription: $this->subscription);

        $this->subscription->cancellation_reason = 'payment_failed';
        $this->subscription->save();

        $this->enqueueRecreditJobs();
    }

    // -- Helpers ----------------------------------------------------------------------

    /** Rails: `subscription.incomplete? && invoice.open? && invoice.subscription?`. */
    protected function gatingStillPending(): bool
    {
        return $this->subscription->incomplete()
            && $this->invoice->statusEnum() === InvoiceStatus::Open
            && $this->invoice->isSubscription();
    }

    protected function paymentRule()
    {
        return $this->subscription->activationRules()->where('type', 'payment')->sole();
    }

    protected function enqueueRecreditJobs(): void
    {
        // Rails: invoice.credits.coupon_kind → AppliedCoupons::RecreditJob.
        // The frozen schema has no credit_kind column; coupon credits are the
        // ones carrying an applied_coupon_id.
        $this->invoice->credits()
            ->whereNotNull('applied_coupon_id')
            ->each(fn ($credit) => \App\Services\AppliedCoupons\RecreditService::call(credit: $credit));

        // TODO(port): CreditNotes::RecreditJob + WalletTransactions::RecreditJob
        // for credit_note_kind credits and outbound wallet transactions.
    }

    /**
     * Rails: `succeeded_payment` — the most recent succeeded payment of the
     * invoice (its PSP-side reference is updated once the invoice is
     * numbered).
     */
    protected function succeededPayment(): ?Payment
    {
        return Payment::query()
            ->where('invoice_id', $this->invoice->id)
            ->where('payable_payment_status', 'succeeded')
            ->orderByDesc('created_at')
            ->first();
    }
}
