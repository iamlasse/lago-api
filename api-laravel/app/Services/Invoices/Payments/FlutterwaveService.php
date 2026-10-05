<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use Throwable;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Models\PaymentIntent;
use App\Services\BaseService;
use App\Values\FlutterwavePayment;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\UpdateService;
use App\Services\PaymentProviders\FindService;

/**
 * Port of Rails' Invoices::Payments::FlutterwaveService — the webhook-driven
 * `update_payment_status` leg and the hosted-checkout `generate_payment_url`
 * leg (POST {api_url}/payments standardized checkout).
 *
 * update_payment_status semantics: metadata payment_type "one-time"
 * recreates the payment (invoice found by lago_invoice_id, payment_attempts
 * incremented), otherwise the payment is found by provider_payment_id
 * (missing -> "flutterwave_payment" not-found failure); an already-succeeded
 * payable short-circuits; the invoice's payment_status follows.
 */
class FlutterwaveService extends BaseService
{
    public const PROVIDER_NAME = 'Flutterwave';

    /**
     * Rails: `update_payment_status`.
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
        FlutterwavePayment $flutterwavePayment,
        ?int $amountCents = null,
    ): BaseResult {
        $result = static::makeResult('payment', 'invoice');

        try {
            if ($flutterwavePayment->metadataValue('payment_type') === 'one-time') {
                $payment = self::createPayment($flutterwavePayment, $amountCents, $result);

                if ($result->failure()) {
                    return $result;
                }
            } else {
                $payment = Payment::query()->where('provider_payment_id', $flutterwavePayment->id)->first();
            }

            if ($payment === null) {
                // Rails: not_found_failure!(resource: "flutterwave_payment").
                return $result->notFoundFailure('flutterwave_payment');
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

    /** Rails: `generate_payment_url` — a Flutterwave checkout session. */
    public static function generatePaymentUrl(Invoice $invoice, PaymentIntent $paymentIntent): BaseResult
    {
        $result = static::makeResult('payment_url');
        $customer = $invoice->customer;
        $organization = $invoice->organization;
        $provider = self::paymentProviderFor($customer);

        if ($provider === null) {
            return $result;
        }

        $body = [
            // Rails: Money.from_cents(invoice.total_amount_cents, currency).to_f.
            'amount' => $invoice->total_amount_cents / 100,
            'tx_ref' => $invoice->id,
            'currency' => mb_strtoupper($invoice->currency),
            'redirect_url' => self::successRedirectUrl($provider),
            'customer' => [
                'email' => $customer->email,
                'phone_number' => $customer->phone ?? '',
                'name' => $customer->name ?: $customer->email,
            ],
            'customizations' => array_filter([
                'title' => $organization->name.' - Invoice Payment',
                'description' => 'Payment for Invoice #'.$invoice->number,
                'logo' => $organization->logo_url,
            ], fn (mixed $v): bool => $v !== null && $v !== ''),
            'configuration' => [
                'session_duration' => 30,
            ],
            'meta' => [
                'lago_customer_id' => $customer->id,
                'lago_invoice_id' => $invoice->id,
                'lago_organization_id' => $organization->id,
                'lago_invoice_number' => $invoice->number,
                'payment_type' => 'one-time',
            ],
        ];

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer '.(string) ($provider->secretKey() ?? ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post(\App\Models\PaymentProvider::FLUTTERWAVE_API_URL.'/payments', $body);

            $response->throw();
        } catch (Throwable $e) {
            // Rails: rescue LagoHttpClient::HttpError -> third_party_failure.
            return $result->thirdPartyFailure(
                thirdParty: self::PROVIDER_NAME,
                errorCode: 'http_error',
                errorMessage: $e->getMessage(),
            );
        }

        $result->payment_url = $response->json('data.link');

        return $result;
    }

    /** Rails: create_payment. */
    private static function createPayment(FlutterwavePayment $flutterwavePayment, ?int $amountCents, BaseResult $result): ?Payment
    {
        $invoice = Invoice::query()
            ->where('id', $flutterwavePayment->metadataValue('lago_invoice_id'))
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
            'provider_payment_id' => $flutterwavePayment->id,
        ]);
        $payment->save();

        return $payment;
    }

    /** Rails: success_redirect_url. */
    private static function successRedirectUrl(\App\Models\PaymentProvider $provider): string
    {
        return (string) ($provider->successRedirectUrl() ?: \App\Models\PaymentProvider::FLUTTERWAVE_SUCCESS_REDIRECT_URL);
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
