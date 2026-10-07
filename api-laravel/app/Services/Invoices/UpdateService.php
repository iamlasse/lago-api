<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Jobs\SendWebhookJob;
use App\Models\Subscription;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;

/**
 * Port of Rails' Invoices::UpdateService
 * (app/services/invoices/update_service.rb) — the payment-status /
 * metadata / paid-amount update behind PUT /api/v1/invoices/:id.
 *
 * TODO(port) emission points left at their exact Rails positions:
 * PaymentIntents::ExpireJob, customer dunning reset (payment requests +
 * dunning campaigns), Invoices::UpdateFeesPaymentStatusJob,
 * Invoices::PrepaidCreditJob, Subscriptions::ActivationRules::Payment::ResolveJob,
 * Hubspot update, Utils::ActivityLog and the invoice metadata store
 * (Metadata::InvoiceMetadata is unported — the count validation runs, the
 * write is skipped).
 */
class UpdateService extends \App\Services\BaseService
{
    /** Rails: Metadata::InvoiceMetadata::COUNT_PER_INVOICE. */
    private const METADATA_COUNT_PER_INVOICE = 5;

    public function __construct(
        private readonly ?Invoice $invoice,
        private readonly array $params,
        private readonly bool $webhookNotification = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');
        $params = $this->params;

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if ($this->invoice->isDraft() && array_key_exists('metadata', $params)) {
            return $result->notAllowedFailure('metadata_on_draft_invoice');
        }

        if (array_key_exists('payment_status', $params) && ! $this->validPaymentStatus($params['payment_status'])) {
            return $result->singleValidationFailure('value_is_invalid', 'payment_status');
        }

        if (! $this->validMetadataCount($params['metadata'] ?? null)) {
            return $result->singleValidationFailure('invalid_count', 'metadata');
        }

        $oldPaymentStatus = $this->invoice->paymentStatusEnum();

        if (array_key_exists('payment_status', $params)) {
            $paymentStatus = InvoicePaymentStatus::fromOption((string) $params['payment_status']);

            $this->invoice->payment_status = $paymentStatus;
        }

        if ($this->invoice->isDraft() && $oldPaymentStatus !== $this->invoice->paymentStatusEnum()) {
            return $result->notAllowedFailure('payment_status_update_on_draft_invoice');
        }

        if (array_key_exists('ready_for_payment_processing', $params) && ! $this->invoice->isVoided()) {
            $this->invoice->ready_for_payment_processing = (bool) $params['ready_for_payment_processing'];
        }

        if (array_key_exists('total_paid_amount_cents', $params)
            && ($params['total_paid_amount_cents'] ?? null) !== null
            && $params['total_paid_amount_cents'] !== '') {
            $this->invoice->total_paid_amount_cents = (int) $params['total_paid_amount_cents'];
        }

        return $this->rescueFailures(function () use ($result, $params, $oldPaymentStatus): BaseResult {
            DB::transaction(function (): void {
                if ($this->invoice->isPaymentOverdue() && $this->invoice->paymentSucceeded()) {
                    $this->invoice->payment_overdue = false;

                    // TODO(port): when a payment request of this invoice
                    // belongs to a dunning campaign, Rails resets the
                    // customer's dunning counters for the invoice currency
                    // (payment requests + dunning campaigns are unported).
                }

                // Rails: invoice.save! — RecordInvalid maps to a validation
                // failure; Laravel surfaces presence errors explicitly.
                $errors = $this->invoice->validateAttributes();

                if ($errors !== []) {
                    BaseResult::of('invoice')->recordValidationFailure($errors)->raiseIfError();
                }

                $this->invoice->save();

                // TODO(port): Invoices::Metadata::UpdateService — the invoice
                // metadata table/model is unported; the validated payload is
                // dropped here.
            });

            $this->schedulePostProcessingJobs($params, $oldPaymentStatus);

            $result->invoice = $this->invoice;

            return $result;
        }, $result);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function schedulePostProcessingJobs(array $params, ?InvoicePaymentStatus $oldPaymentStatus): void
    {
        if (array_key_exists('payment_status', $params)) {
            $paymentStatus = $this->invoice->paymentStatusEnum();

            $this->handlePrepaidCredits($paymentStatus);
            $this->handlePaymentGatedActivation($paymentStatus);
            $this->updateFeesPaymentStatus();
            $this->expireOpenCheckoutUrls($oldPaymentStatus, $paymentStatus);

            if ($oldPaymentStatus !== $paymentStatus && $this->invoice->isVisible()) {
                $this->deliverWebhook();
                // TODO(port): Utils::ActivityLog.produce_after_commit
                // ("invoice.payment_status_updated").
            }
        }

        // TODO(port): update_hubspot_invoice when
        // invoice.should_update_hubspot_invoice? (integrations milestone).
    }

    /**
     * When the invoice is settled, cancel any still-open hosted checkout
     * session (Rails: PaymentIntents::ExpireJob.perform_after_commit when a
     * active intent exists).
     */
    private function expireOpenCheckoutUrls(?InvoicePaymentStatus $oldPaymentStatus, ?InvoicePaymentStatus $paymentStatus): void
    {
        if ($paymentStatus !== InvoicePaymentStatus::Succeeded) {
            return;
        }

        if ($oldPaymentStatus === InvoicePaymentStatus::Succeeded) {
            return;
        }

        if (! \App\Models\PaymentIntent::query()->where('status', 0)->where('invoice_id', $this->invoice->id)->exists()) {
            return;
        }

        dispatch(new \App\Jobs\PaymentIntentsExpireJob($this->invoice));
    }

    /** TODO(port): Invoices::UpdateFeesPaymentStatusJob. */
    private function updateFeesPaymentStatus(): void
    {
        //
    }

    /** TODO(port): Invoices::PrepaidCreditJob (wallets are unported). */
    private function handlePrepaidCredits(?InvoicePaymentStatus $paymentStatus): void
    {
        if ($this->invoice->typeEnum() !== \App\Enums\InvoiceType::Credit) {
            return;
        }

        if (! in_array($paymentStatus, [InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Failed], true)) {
            return;
        }

        // TODO(port): Invoices::PrepaidCreditJob.perform_after_commit(invoice, payment_status).
    }

    /**
     * TODO(port): Subscriptions::ActivationRules::Payment::ResolveJob —
     * activation rules are a later milestone.
     */
    private function handlePaymentGatedActivation(?InvoicePaymentStatus $paymentStatus): void
    {
        if (! $this->invoice->subscriptionGated()) {
            return;
        }

        if (! in_array($paymentStatus, [InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Failed], true)) {
            return;
        }

        // Rails: invoice.subscriptions.find(&:incomplete?) — the gated
        // subscription this invoice settles.
        $subscription = $this->invoice->subscriptions->first(fn (Subscription $s) => $s->incomplete());

        if ($subscription === null) {
            return;
        }

        dispatch(new \App\Jobs\Subscriptions\ActivationRules\Payment\ResolveJob($subscription, $this->invoice, $paymentStatus === InvoicePaymentStatus::Succeeded ? 'succeeded' : 'failed'));
    }

    private function deliverWebhook(): void
    {
        if (! $this->webhookNotification) {
            return;
        }

        SendWebhookJob::performLater('invoice.payment_status_updated', $this->invoice);
    }

    /** Rails: Invoice::PAYMENT_STATUS.include?(payment_status&.to_sym). */
    private function validPaymentStatus(mixed $paymentStatus): bool
    {
        return InvoicePaymentStatus::fromOption((string) $paymentStatus) !== null;
    }

    private function validMetadataCount(mixed $metadata): bool
    {
        if ($metadata === null || $metadata === [] || $metadata === '') {
            return true;
        }

        return count((array) $metadata) <= self::METADATA_COUNT_PER_INVOICE;
    }
}
