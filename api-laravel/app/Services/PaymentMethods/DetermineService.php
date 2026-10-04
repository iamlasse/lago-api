<?php

declare(strict_types=1);

namespace App\Services\PaymentMethods;

use App\Models\Invoice;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' PaymentMethods::DetermineService — picks the payment
 * method for an automatic payment attempt. `paymentMethodParams` takes
 * precedence (the retry-with-override path); a "manual" override or no
 * match yields null (= skip the automatic payment).
 */
class DetermineService extends BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly Customer $customer,
        private readonly array $paymentMethodParams = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_method');

        $result->payment_method = $this->paymentMethodParams !== []
            ? $this->determineOverridePaymentMethod()
            : $this->determineInvoicePaymentMethod();

        return $result;
    }

    private function determineOverridePaymentMethod(): ?\App\Models\PaymentMethod
    {
        if (($this->paymentMethodParams['payment_method_type'] ?? null) === 'manual') {
            return null;
        }

        if (($this->paymentMethodParams['payment_method_id'] ?? null) !== null) {
            return $this->customer->paymentMethods()
                ->where('id', $this->paymentMethodParams['payment_method_id'])
                ->first();
        }

        return $this->defaultPaymentMethod();
    }

    private function determineInvoicePaymentMethod(): ?\App\Models\PaymentMethod
    {
        $type = $this->invoice->typeEnum()?->label();

        return match ($type) {
            'subscription', 'advance_charges', 'progressive_billing' => $this->determineSubscriptionPaymentMethod(),
            'credit' => $this->determineCreditPaymentMethod(),
            default => $this->defaultPaymentMethod(),
        };
    }

    private function determineSubscriptionPaymentMethod(): ?\App\Models\PaymentMethod
    {
        $subscription = $this->invoice->invoiceSubscriptions->first()?->subscription;

        if ($subscription === null) {
            return null;
        }

        if ($subscription->payment_method_type === 'manual') {
            return null;
        }

        if ($subscription->payment_method_id !== null) {
            return $this->customer->paymentMethods()->where('id', $subscription->payment_method_id)->first();
        }

        return $this->defaultPaymentMethod();
    }

    private function determineCreditPaymentMethod(): ?\App\Models\PaymentMethod
    {
        $walletTransaction = \App\Models\WalletTransaction::query()
            ->where('invoice_id', $this->invoice->id)
            ->first();

        if ($walletTransaction === null) {
            return null;
        }

        if ($walletTransaction->payment_method_type === 'manual') {
            return null;
        }

        if ($walletTransaction->payment_method_id !== null) {
            return $this->customer->paymentMethods()->where('id', $walletTransaction->payment_method_id)->first();
        }

        if (in_array($walletTransaction->sourceEnum()?->label(), ['interval', 'threshold'], true)) {
            // TODO(port): the wallet's active recurring transaction rule
            // (RecurringTransactionRule has no model yet) — Rails falls back
            // to rule.payment_method_id before the wallet's own setting.
        }

        $wallet = $walletTransaction->wallet;

        if ($wallet === null) {
            return $this->defaultPaymentMethod();
        }

        if ($wallet->payment_method_type === 'manual') {
            return null;
        }

        if ($wallet->payment_method_id !== null) {
            return $this->customer->paymentMethods()->where('id', $wallet->payment_method_id)->first();
        }

        return $this->defaultPaymentMethod();
    }

    /** Rails: customer.default_payment_method. */
    private function defaultPaymentMethod(): ?\App\Models\PaymentMethod
    {
        return $this->customer->paymentMethods()->where('is_default', true)->first();
    }
}
