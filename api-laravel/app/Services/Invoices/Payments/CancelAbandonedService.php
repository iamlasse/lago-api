<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use App\Services\Invoices\UpdateService;
use App\Services\PaymentProviders\CancelPaymentService;
use App\Services\PaymentProviders\Stripe\PermissionError;
use App\Services\PaymentProviders\Stripe\AuthenticationError;
use App\Services\PaymentProviders\Stripe\InvalidRequestError;
use App\Services\PaymentProviders\Stripe\Payments\RetrieveService;

/**
 * Port of Rails' Invoices::Payments::CancelAbandonedService
 * (app/services/invoices/payments/cancel_abandoned_service.rb) — reclaims
 * invoice payments whose 3DS redirect never came back: reads the live
 * intent from Stripe, cancels it if the redirect is truly dead, and
 * re-arms the invoice for payment processing.
 */
class CancelAbandonedService extends BaseService
{
    /**
     * Abandoned after a day, and only recovered while recent: cancelling
     * something older means charging and dunning an end customer who has
     * heard nothing for months.
     */
    public const ABANDONED_PERIOD_MINUTES = 24 * 60;

    public const RECOVERY_WINDOW_DAYS = 30;

    public function __construct(private readonly Payment $payment)
    {
        parent::__construct();
    }

    /** Rails: Invoices::Payments::CancelAbandonedService.recovery_range. */
    public static function recoveryRange(): array
    {
        return [now()->subDays(self::RECOVERY_WINDOW_DAYS), now()->subMinutes(self::ABANDONED_PERIOD_MINUTES)];
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment');

        $result->payment = $this->payment;

        if (! $this->abandoned()) {
            return $result;
        }

        $intent = $this->stripeIntent();
        if ($intent === null) {
            return $result;
        }

        // The intent is already over at the provider and its webhook never
        // reached us.
        $failedStatuses = \App\Models\PaymentProvider::STRIPE_FAILED_STATUSES;
        if (in_array($intent->status, $failedStatuses, true)) {
            $this->payment->status = $intent->status;
            $this->payment->payable_payment_status = $this->payment->paymentProvider->determinePaymentStatus($this->payment->status);
            $this->payment->save();

            $this->unlockInvoice();

            return $result;
        }

        // Only cards do 3DS, and we ask the provider because we do not store
        // the method our side.
        if ($intent->status !== 'requires_action' || $intent->payment_method_type !== 'card') {
            return $result;
        }

        CancelPaymentService::callBang(payment: $this->payment);

        if ($this->payment->refresh()->payablePaymentStatus() !== 'failed') {
            return $result;
        }

        $this->unlockInvoice();

        return $result;
    }

    private function abandoned(): bool
    {
        $payable = $this->payment->payable;

        if (! $payable instanceof Invoice) {
            return false;
        }

        if ($this->payment->paymentProviderType() !== 'stripe') {
            return false;
        }

        if (! $this->abandonedAtRedirect()) {
            return false;
        }

        if ($payable->paymentSucceeded() || $payable->isVoided() || $payable->isClosed()) {
            return false;
        }

        // Cancelling would land a failed payment status on the invoice, and
        // that resolves the activation through Invoices::UpdateService. That
        // flow has its own window and its own clock.
        return ! $this->payment->gatedSubscriptionActivation();
    }

    private function abandonedAtRedirect(): bool
    {
        [$start, $end] = self::recoveryRange();

        $updatedAt = $this->payment->updated_at;

        return $this->payment->status === 'requires_action'
            && $this->payment->payablePaymentStatus() === 'processing'
            && (($this->payment->provider_payment_data['type'] ?? null) === 'redirect_to_url')
            && $updatedAt !== null
            && $updatedAt->betweenIncluded($start, $end);
    }

    /**
     * @return null|BaseResult the live-intent result (status +
     *                         payment_method_type), or null when the intent is unreadable.
     */
    private function stripeIntent(): ?BaseResult
    {
        try {
            return RetrieveService::call(payment: $this->payment);
        } catch (AuthenticationError|PermissionError|InvalidRequestError $e) {
            Log::warning(sprintf(
                'Invoices::Payments::CancelAbandonedService: cannot read intent %s for payment %s: %s',
                (string) $this->payment->provider_payment_id,
                $this->payment->id,
                $e::class,
            ));

            return null;
        }
        // RateLimitError propagates so the job retries instead of skipping
        // the payment (Rails lets Stripe::RateLimitError through).
    }

    /**
     * The invoice payment status is left alone. A failed payment on a
     * pending invoice is what returns it to dunning, and writing a status
     * here would duplicate the provider's webhook.
     */
    private function unlockInvoice(): void
    {
        $payable = $this->payment->payable()->firstOrFail();

        if ($payable->refresh()->paymentSucceeded()) {
            return;
        }

        UpdateService::callBang(invoice: $payable, params: ['ready_for_payment_processing' => true]);
    }
}
