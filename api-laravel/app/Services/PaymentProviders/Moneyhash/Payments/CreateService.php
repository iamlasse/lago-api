<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Moneyhash\Payments;

use Throwable;
use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use App\Services\Invoices\Payments\ConnectionError;

/**
 * Port of Rails' PaymentProviders::Moneyhash::Payments::CreateService —
 * creates a Moneyhash payment intent for the invoice
 * (POST {api_base}/api/v1.1/payments/intent/, x-Api-Key, merchant_initiated
 * true, payment_type UNSCHEDULED, card_token from the stored method,
 * custom_fields carrying the lago_* metadata plus the provider customer's
 * mh_custom_fields).
 *
 * Ported semantics:
 *  - payment.provider_payment_id = data.id; payment.status = data.status ||
 *    data.active_transaction.status || "PENDING"; payable_payment_status the
 *    normalized one;
 *  - an HTTP error fails the payment ("moneyhash_error") AND re-raises
 *    (reraise:); connection failures raise Invoices::Payments::ConnectionError.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly Payment $payment,
        private readonly string $reference,
        /** @var array<string, mixed> */
        private readonly array $metadata = [],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment', 'error_message', 'error_code', 'reraise', 'should_retry');

        $result->payment = $this->payment;

        $providerCustomer = $this->payment->paymentProviderCustomer;
        $paymentProvider = $providerCustomer?->paymentProvider;

        if ($providerCustomer === null || $paymentProvider === null) {
            return $result->serviceFailure(code: 'moneyhash_error', message: 'Missing payment provider customer');
        }

        /** @var array<string, mixed>|null $response */
        $response = null;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-Api-Key' => (string) ($paymentProvider->apiKey() ?? ''),
            ])->post(self::intentUrl(), $this->paymentParams($paymentProvider, $providerCustomer))
                ->throw()
                ->json();
        } catch (ConnectionException $e) {
            throw new ConnectionError($e);
        } catch (Throwable $e) {
            return $this->prepareFailedResult($result, $e, reraise: true);
        }

        $data = is_array($response) ? ($response['data'] ?? []) : [];

        $this->payment->provider_payment_id = $data['id'] ?? null;
        $this->payment->status = (string) ($data['status']
            ?? $data['active_transaction']['status']
            ?? 'PENDING');
        $this->payment->payable_payment_status = $paymentProvider->determinePaymentStatus($this->payment->status);
        $this->payment->save();

        $result->payment = $this->payment;

        return $result;
    }

    /** Rails: MoneyhashProvider#webhook_end_point. */
    private static function webhookEndPoint(PaymentProvider $paymentProvider): string
    {
        $base = mb_rtrim((string) env('LAGO_API_URL', ''), '/');

        return $base.'/webhooks/moneyhash/'.$paymentProvider->organization_id.'?code='.urlencode($paymentProvider->code);
    }

    private static function intentUrl(): string
    {
        return PaymentProvider::moneyhashApiBaseUrl().'/api/v1.1/payments/intent/';
    }

    /** Rails: create_moneyhash_payment params. */
    private function paymentParams(PaymentProvider $paymentProvider, \App\Models\PaymentProviderCustomer $providerCustomer): array
    {
        $invoice = $this->payment->payable;
        $customer = $this->payment->customer;

        /** @var array<string, mixed> $billingData */
        $billingData = $providerCustomer->mhBillingData();
        /** @var array<string, mixed> $mhCustomFields */
        $mhCustomFields = $providerCustomer->mhCustomFields();

        $subscription = $invoice?->subscriptions->first();

        $customFields = array_merge([
            // plan/subscription
            'lago_plan_id' => (string) ($subscription?->plan_id ?? ''),
            'lago_subscription_external_id' => (string) ($subscription?->external_id ?? ''),
            // payable
            'lago_payable_id' => $invoice?->id,
            'lago_payable_type' => $invoice ? class_basename($invoice) : 'Invoice',
            'lago_payable_invoice_type' => $invoice->invoice_type instanceof \BackedEnum
                ? $invoice->invoice_type->value
                : (string) ($invoice->invoice_type ?? ''),
            // mit flag
            'lago_mit' => true,
            // service
            'lago_mh_service' => 'PaymentProviders::Moneyhash::Payments::CreateService',
            // request
            'lago_request' => 'invoice_automatic_payment',
        ], $mhCustomFields);

        return [
            'amount' => (int) $this->payment->amount_cents / 100,
            'amount_currency' => mb_strtoupper($this->payment->amount_currency),
            'flow_id' => $paymentProvider->flowId(),
            'billing_data' => $billingData,
            'customer' => $providerCustomer->provider_customer_id,
            'webhook_url' => self::webhookEndPoint($paymentProvider),
            'payment_type' => 'UNSCHEDULED',
            'merchant_initiated' => true,
            'recurring_data' => ['agreement_id' => $customer?->id],
            'card_token' => $this->paymentMethodId($providerCustomer),
            'custom_fields' => $customFields,
        ];
    }

    /** Rails: moneyhash_payment_method_id. */
    private function paymentMethodId(\App\Models\PaymentProviderCustomer $providerCustomer): ?string
    {
        return $this->payment->paymentMethod?->provider_method_id
            ?? $providerCustomer->legacyProviderMethodId();
    }

    /** Rails: prepare_failed_result. */
    private function prepareFailedResult(BaseResult $result, Throwable $error, bool $reraise = false): BaseResult
    {
        $result->error_message = $error->getMessage();
        $result->error_code = 'http_error';
        $result->reraise = $reraise;

        $this->payment->status = 'failed';
        $this->payment->payable_payment_status = 'failed';
        $this->payment->save();

        return $result->serviceFailure(
            code: 'moneyhash_error',
            message: 'http_error: '.$error->getMessage(),
            error: $error,
        );
    }
}
