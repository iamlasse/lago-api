<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use Throwable;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Support\MoneyMath;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Models\PaymentIntent;
use App\Services\BaseService;
use App\Values\CashfreePayment;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\UpdateService;
use App\Services\PaymentProviders\FindService;

/**
 * Port of Rails' Invoices::Payments::CashfreeService — the webhook-driven
 * `update_payment_status` leg and the hosted-checkout `generate_payment_url`
 * leg (Cashfree payment links, POST {BASE_URL} with x-client-id /
 * x-client-secret / x-api-version headers).
 *
 * update_payment_status semantics: metadata payment_type "one-time"
 * recreates the payment (invoice found by lago_invoice_id, payment_attempts
 * incremented), otherwise the payment is found by provider_payment_id
 * (missing -> "cashfree_payment" not-found failure); an already-succeeded
 * payable short-circuits; the invoice's payment_status follows.
 */
class CashfreeService extends BaseService
{
    public const PROVIDER_NAME = 'Cashfree';

    /**
     * Rails: `update_payment_status`.
     *
     * @param  array<string, mixed>  $metadata
     */
    /**
     * The Rails services dispatch actions by name (call!(:update_payment_status, ...));
     * the port exposes them as static entrypoints instead — this instance body is
     * never invoked.
     */
    public function execute(): BaseResult
    {
        throw new \LogicException(static::class." is dispatched through its static action entrypoints");
    }

    public static function updatePaymentStatus(
        string $organizationId,
        string $status,
        CashfreePayment $cashfreePayment,
        ?int $amountCents = null,
    ): BaseResult {
        $result = static::makeResult('payment', 'invoice');

        try {
            if ($cashfreePayment->metadataValue('payment_type') === 'one-time') {
                $payment = self::createPayment($cashfreePayment, $amountCents, $result);

                if ($result->failure()) {
                    return $result;
                }
            } else {
                $payment = Payment::query()->where('provider_payment_id', $cashfreePayment->id)->first();
            }

            if ($payment === null) {
                // Rails: not_found_failure!(resource: "cashfree_payment").
                return $result->notFoundFailure('cashfree_payment');
            }

            $result->payment = $payment;
            $result->invoice = $invoice = $payment->payable;

            if (! $invoice instanceof Invoice || $invoice->paymentSucceeded()) {
                return $result;
            }

            $payment->status = $status;
            $payablePaymentStatus = $payment->paymentProvider?->determinePaymentStatus($payment->status);
            $payment->payable_payment_status = $payablePaymentStatus;
            $payment->save();

            if ($payablePaymentStatus === 'succeeded') {
                SendWebhookJob::performLater('payment.succeeded', $payment);
            }

            self::updateInvoicePaymentStatus($invoice, (string) $payablePaymentStatus);

            return $result;
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /** Rails: `generate_payment_url` — a Cashfree payment link. */
    public static function generatePaymentUrl(Invoice $invoice, PaymentIntent $paymentIntent): BaseResult
    {
        $result = static::makeResult('payment_url');
        $customer = $invoice->customer;
        $provider = self::paymentProviderFor($customer);

        if ($provider === null) {
            return $result;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'x-client-id' => (string) ($provider->clientId() ?? ''),
                'x-client-secret' => (string) ($provider->clientSecret() ?? ''),
                'x-api-version' => \App\Models\PaymentProvider::CASHFREE_API_VERSION,
            ])->post(\App\Models\PaymentProvider::cashfreeBaseUrl(), self::paymentUrlParams($invoice, $customer, $provider, $paymentIntent));

            $response->throw();
        } catch (Throwable $e) {
            // Rails: rescue LagoHttpClient::HttpError -> third_party_failure.
            return $result->thirdPartyFailure(
                thirdParty: self::PROVIDER_NAME,
                errorCode: 'http_error',
                errorMessage: $e->getMessage(),
            );
        }

        $result->payment_url = $response->json('link_url');

        return $result;
    }

    /** Rails: Money.from_amount(raw.to_d, currency).cents. */
    public static function amountToCents(mixed $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        return MoneyMath::round((float) $amount * 100);
    }

    /**
     * Rails: create_payment — the invoice comes from the link notes'
     * lago_invoice_id; payment_attempts is incremented.
     */
    private static function createPayment(CashfreePayment $cashfreePayment, ?int $amountCents, BaseResult $result): ?Payment
    {
        $invoice = Invoice::query()
            ->where('id', $cashfreePayment->metadataValue('lago_invoice_id'))
            ->first();

        if ($invoice === null) {
            $result->notFoundFailure('invoice');

            return null;
        }

        $invoice->payment_attempts = (int) $invoice->payment_attempts + 1;
        $invoice->save();

        $customer = $invoice->customer;
        $provider = self::paymentProviderFor($customer);
        $providerCustomer = $customer?->paymentProviderCustomers()
            ->where('payment_provider_id', $provider?->id)
            ->first();

        $payment = new Payment([
            'organization_id' => $invoice->organization_id,
            'payable_type' => 'Invoice',
            'payable_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'payment_provider_id' => $provider?->id,
            'payment_provider_customer_id' => $providerCustomer?->id,
            'amount_cents' => $amountCents ?? $invoice->total_due_amount_cents,
            'amount_currency' => $invoice->currency,
            'status' => 'pending',
            'provider_payment_id' => $cashfreePayment->id,
        ]);
        $payment->save();

        return $payment;
    }

    /** Rails: payment_url_params. */
    private static function paymentUrlParams(
        Invoice $invoice,
        Customer $customer,
        \App\Models\PaymentProvider $provider,
        PaymentIntent $paymentIntent,
    ): array {
        return [
            'customer_details' => [
                'customer_phone' => $customer->phone ?? '9999999999',
                'customer_email' => $customer->email,
                'customer_name' => $customer->name,
            ],
            'link_notify' => [
                'send_sms' => false,
                'send_email' => false,
            ],
            'link_meta' => [
                'upi_intent' => true,
                'return_url' => self::successRedirectUrl($provider),
            ],
            'link_notes' => [
                'lago_customer_id' => $customer->id,
                'lago_invoice_id' => $invoice->id,
                'invoice_issuing_date' => $invoice->issuing_date?->toDateString(),
                'payment_type' => 'one-time',
            ],
            'link_id' => \Illuminate\Support\Str::uuid()->toString().'.'.$invoice->payment_attempts,
            'link_amount' => $invoice->total_due_amount_cents / 100,
            'link_currency' => mb_strtoupper($invoice->currency),
            'link_purpose' => $invoice->id,
            'link_expiry_time' => $paymentIntent->expires_at?->toISOString(),
            'link_partial_payments' => false,
            'link_auto_reminders' => false,
        ];
    }

    /** Rails: success_redirect_url. */
    private static function successRedirectUrl(\App\Models\PaymentProvider $provider): string
    {
        return (string) ($provider->successRedirectUrl() ?: \App\Models\PaymentProvider::CASHFREE_SUCCESS_REDIRECT_URL);
    }

    /** Rails: update_invoice_payment_status. */
    private static function updateInvoicePaymentStatus(Invoice $invoice, string $paymentStatus): void
    {
        $params = [
            'payment_status' => $paymentStatus,
            'ready_for_payment_processing' => $paymentStatus !== 'succeeded',
        ];

        if ($paymentStatus === 'succeeded') {
            $params['total_paid_amount_cents'] = (int) Payment::query()
                ->where('payable_type', 'Invoice')
                ->where('payable_id', $invoice->id)
                ->where('payable_payment_status', 'succeeded')
                ->sum('amount_cents');
        }

        UpdateService::callBang(
            invoice: $invoice,
            params: $params,
            webhookNotification: true,
        );
    }

    /** Rails: Customers::PaymentProviderFinder#payment_provider. */
    private static function paymentProviderFor(?Customer $customer): ?\App\Models\PaymentProvider
    {
        if ($customer?->payment_provider === null) {
            return null;
        }

        $findResult = FindService::call(
            organizationId: $customer->organization_id,
            code: $customer->payment_provider_code,
            paymentProviderType: $customer->payment_provider,
        );

        if ($findResult->failure()) {
            return null;
        }

        return $findResult->payment_provider;
    }
}
