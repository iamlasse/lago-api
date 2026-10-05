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
use App\Services\Failures\FailedResult;
use App\Services\Invoices\UpdateService;
use App\Services\PaymentProviders\FindService;
use App\Services\PaymentProviders\Adyen\Client;
use App\Services\PaymentProviders\Adyen\AdyenError;

/**
 * Port of Rails' Invoices::Payments::AdyenService — the webhook-driven
 * `update_payment_status` leg and the hosted-checkout `generate_payment_url`
 * leg (Adyen payment links).
 *
 * update_payment_status semantics:
 *  - metadata payment_type "one-time" (hosted checkout) recreates the
 *    payment from the event (invoice found by lago_invoice_id AND
 *    organization_id, payment_attempts incremented); any other event finds
 *    the payment by provider_payment_id;
 *  - a missing payment fails with "adyen_payment" (Rails'
 *    not_found_failure! caught by the FailedResult rescue);
 *  - an already-succeeded payable short-circuits;
 *  - payment.status gets the raw Adyen status, payable_payment_status the
 *    normalized one, and the invoice's payment_status follows
 *    (Invoices::UpdateService with ready_for_payment_processing /
 *    total_paid_amount_cents).
 */
class AdyenService extends BaseService
{
    public const PROVIDER_NAME = 'Adyen';

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
        string $providerPaymentId,
        string $status,
        ?int $amountCents = null,
        array $metadata = [],
    ): BaseResult {
        $result = static::makeResult('payment', 'invoice');

        try {
            if (($metadata['payment_type'] ?? null) === 'one-time') {
                $payment = self::createPayment($organizationId, $providerPaymentId, $amountCents, $metadata, $result);

                if ($result->failure()) {
                    return $result;
                }
            } else {
                $payment = Payment::query()->where('provider_payment_id', $providerPaymentId)->first();
            }

            if ($payment === null) {
                // Rails: not_found_failure!(resource: "adyen_payment").
                return $result->notFoundFailure('adyen_payment');
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

            // TODO(port): Integrations::Aggregator::Payments::CreateJob when
            // payment.should_sync_payment? (accounting integrations milestone).

            self::updateInvoicePaymentStatus($invoice, (string) $payablePaymentStatus);

            return $result;
        } catch (FailedResult $e) {
            // Rails: rescue BaseService::FailedResult -> result.fail_with_error!.
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /** Rails: `generate_payment_url` — a payment link for the invoice. */
    public static function generatePaymentUrl(Invoice $invoice, PaymentIntent $paymentIntent): BaseResult
    {
        $result = static::makeResult('payment_url');
        $customer = $invoice->customer;
        $provider = self::paymentProviderFor($customer);

        if ($provider === null) {
            return $result;
        }

        $client = new Client(
            apiKey: (string) ($provider->apiKey() ?? ''),
            environment: $provider->adyenStyleEnvironment(),
            livePrefix: (string) ($provider->livePrefix() ?? ''),
        );

        try {
            [$status, $response] = $client->call(
                'post',
                'paymentLinks',
                self::paymentUrlParams($invoice, $customer, $provider, $paymentIntent),
                ['Idempotency-Key' => $paymentIntent->id],
            );
        } catch (AdyenError $e) {
            return $result->thirdPartyFailure(
                thirdParty: self::PROVIDER_NAME,
                errorCode: $e->code,
                errorMessage: $e->msg,
            );
        }

        if (Client::responseFailed($status)) {
            $error = Client::errorFromResponse($status, $response);

            return $result->serviceFailure(code: $error->code, message: $error->msg);
        }

        $result->payment_url = $response['url'] ?? null;

        return $result;
    }

    /**
     * Rails: create_payment — the invoice is scoped by lago_invoice_id AND
     * the organization (the same Adyen credentials may serve several
     * organizations); payment_attempts is incremented.
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function createPayment(
        string $organizationId,
        string $providerPaymentId,
        ?int $amountCents,
        array $metadata,
        BaseResult $result,
    ): ?Payment {
        $invoice = Invoice::query()
            ->where('id', $metadata['lago_invoice_id'] ?? null)
            ->where('organization_id', $organizationId)
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
            'amount_currency' => mb_strtoupper($invoice->currency),
            'status' => 'pending',
            'provider_payment_id' => $providerPaymentId,
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
        $params = [
            'reference' => $invoice->number,
            'amount' => [
                'value' => $invoice->total_due_amount_cents,
                'currency' => mb_strtoupper($invoice->currency),
            ],
            'merchantAccount' => $provider->merchantAccount(),
            'returnUrl' => self::successRedirectUrl($provider),
            'shopperReference' => $customer->external_id,
            'storePaymentMethodMode' => 'enabled',
            'recurringProcessingModel' => 'UnscheduledCardOnFile',
            'expiresAt' => $paymentIntent->expires_at?->toISOString(),
            'metadata' => [
                'lago_customer_id' => $customer->id,
                'lago_invoice_id' => $invoice->id,
                'invoice_issuing_date' => $invoice->issuing_date?->toDateString(),
                'invoice_type' => $invoice->invoice_type,
                'payment_type' => 'one-time',
            ],
        ];

        if (($customer->email ?? null) !== null && $customer->email !== '') {
            $params['shopperEmail'] = $customer->email;
        }

        return $params;
    }

    /** Rails: success_redirect_url. */
    private static function successRedirectUrl(\App\Models\PaymentProvider $provider): string
    {
        return (string) ($provider->successRedirectUrl() ?: \App\Models\PaymentProvider::ADYEN_SUCCESS_REDIRECT_URL);
    }

    /**
     * Rails: update_invoice_payment_status — succeeded sums the invoice's
     * succeeded payments into total_paid_amount_cents.
     */
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
