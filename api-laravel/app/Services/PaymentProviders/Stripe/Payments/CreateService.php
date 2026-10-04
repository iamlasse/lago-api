<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\PaymentProviders\Stripe\Client;
use App\Services\Invoices\Payments\RateLimitError;
use App\Services\Invoices\Payments\ConnectionError;
use App\Services\PaymentProviders\Stripe\CardError;
use App\Services\Invoices\Payments\AlreadyPaidError;
use App\Services\PaymentProviders\Stripe\StripeError;
use App\Services\PaymentProviders\Stripe\PermissionError;
use App\Services\PaymentProviders\Stripe\IdempotencyError;
use App\Services\PaymentProviders\Stripe\ApiConnectionError;
use App\Services\PaymentProviders\Stripe\AuthenticationError;
use App\Services\PaymentProviders\Stripe\InvalidRequestError;
use App\Services\PaymentProviders\Stripe\RateLimitError as StripeRateLimitError;

use function array_merge;

/**
 * Port of Rails' PaymentProviders::Stripe::Payments::CreateService —
 * confirms an off-session Stripe PaymentIntent for a pending payment
 * (POST /v1/payment_intents, Idempotency-Key "payment-{payment.id}").
 *
 * Payload fidelity (vs the stripe gem call in Rails):
 *  - amount / currency (downcased) / customer / payment_method_types[] /
 *    confirm=true / off_session / return_url (provider success redirect or
 *    https://stripe.com/) / error_on_requires_action / description /
 *    metadata (caller's metadata + lago_payment_id, lago_payable_id,
 *    lago_payable_type, lago_customer_id, lago_organization_id,
 *    lago_billing_entity_id);
 *  - a customer_balance-only connection pays with
 *    payment_method_data[type]=customer_balance + bank_transfer funding
 *    options (eu_bank_transfer for EUR from the customer's or billing
 *    entity's supported country);
 *  - otherwise payload[payment_method] is the stored method, verified
 *    against Stripe (GET /v1/payment_methods/{id}) with a fallback to the
 *    customer's first listed method (GET
 *    /v1/customers/{id}/payment_methods);
 *  - the Stripe customer's default payment method is re-synced first
 *    (GET /v1/customers/{id} -> provider customer setting);
 *  - a payable with prior 3DS errors drops off_session /
 *    error_on_requires_action; Indian customers always pay on-session.
 *
 * Error mapping: amount_too_small and idempotency errors keep the payment
 * pending; authentication_required retries only when 3DS is supported or
 * the subscription is payment-gated; rate limits / connection failures
 * raise RateLimitError / ConnectionError for job-level retries; any other
 * Stripe error marks the payment failed and is re-raised (reraise:).
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

        $invoice = $this->payable();
        $providerCustomer = $this->payment->paymentProviderCustomer;
        $paymentProvider = $providerCustomer?->paymentProvider;

        if ($providerCustomer === null || $paymentProvider === null) {
            return $result->serviceFailure(code: 'stripe_error', message: 'Missing payment provider customer');
        }

        $apiKey = (string) $paymentProvider->secretKey();
        $client = new Client($apiKey, idempotencyKey: 'payment-'.$this->payment->id);

        try {
            $this->updatePaymentMethodId($client, $providerCustomer);

            if ($invoice !== null) {
                $invoice->refresh();

                if ($invoice->paymentSucceeded()) {
                    throw new AlreadyPaidError('Invoice already paid');
                }
            }

            $stripeResult = $client->call('post', '/v1/payment_intents', $this->paymentIntentPayload($paymentProvider));
        } catch (AlreadyPaidError $e) {
            throw $e;
        } catch (StripeRateLimitError $e) {
            throw new RateLimitError($e);
        } catch (ApiConnectionError $e) {
            throw new ConnectionError($e);
        } catch (StripeError $e) {
            return $this->prepareFailedResult($result, $e, $paymentProvider, reraise: self::isGenericStripeError($e));
        }

        $this->payment->provider_payment_id = $stripeResult['id'] ?? null;
        $this->payment->status = (string) ($stripeResult['status'] ?? '');
        $this->payment->payable_payment_status = $paymentProvider->determinePaymentStatus($this->payment->status);

        if ($this->payment->status === 'requires_action') {
            $nextAction = $stripeResult['next_action'] ?? null;
            $this->payment->provider_payment_data = is_array($nextAction) ? $nextAction : null;
        }

        $this->payment->save();

        if ($this->payment->status === 'requires_action') {
            SendWebhookJob::performLater('payment.requires_action', $this->payment);
        }

        $result->payment = $this->payment;

        return $result;
    }

    /**
     * Rails maps the identified error subclasses through
     * prepare_failed_result; any other StripeError is reraise: true.
     */
    private static function isGenericStripeError(StripeError $error): bool
    {
        return ! $error instanceof AuthenticationError
            && ! $error instanceof CardError
            && ! $error instanceof InvalidRequestError
            && ! $error instanceof PermissionError
            && ! $error instanceof IdempotencyError;
    }

    /**
     * Rails: update_payment_method_id — refresh the provider customer's
     * stored payment method from the Stripe customer's invoice default
     * (or default source).
     */
    private function updatePaymentMethodId(Client $client, \App\Models\PaymentProviderCustomer $providerCustomer): void
    {
        try {
            $stripeCustomer = $client->call('get', '/v1/customers/'.$providerCustomer->provider_customer_id);
        } catch (StripeError) {
            // TODO(port): deliver error webhook + payment status update
            // (Rails leaves these TODOs as well).
            return;
        }

        if (($stripeCustomer['deleted'] ?? false) === true) {
            return;
        }

        $paymentMethodId = $stripeCustomer['invoice_settings']['default_payment_method']
            ?? $stripeCustomer['default_source']
            ?? null;

        if ($paymentMethodId !== null && $paymentMethodId !== $providerCustomer->getFromSettings('payment_method_id')) {
            $providerCustomer->pushToSettings('payment_method_id', $paymentMethodId);
            $providerCustomer->save();
        }
    }

    /** @return array<string, mixed> */
    private function paymentIntentPayload(\App\Models\PaymentProvider $paymentProvider): array
    {
        $providerCustomer = $this->payment->paymentProviderCustomer;
        $invoice = $this->payable();
        $methods = $providerCustomer->getFromSettings('provider_payment_methods') ?? [];

        $payload = [
            'amount' => (int) $this->payment->amount_cents,
            'currency' => mb_strtolower($this->payment->amount_currency),
            'customer' => $providerCustomer->provider_customer_id,
            'payment_method_types' => $methods,
            'confirm' => true,
            'off_session' => $this->offSession($invoice, $methods),
            'return_url' => $this->successRedirectUrl($paymentProvider),
            'error_on_requires_action' => $invoice?->customer?->country !== 'IN',
            'description' => $this->reference,
            'metadata' => $this->enrichedMetadata($invoice),
        ];

        if ($methods === ['customer_balance']) {
            $payload = array_merge($payload, $this->customerBalanceFields());
            unset($payload['payment_method']);
        } else {
            $payload['payment_method'] = $this->stripePaymentMethod();
        }

        if ($this->payableHadAuthenticationError($invoice)) {
            unset($payload['off_session'], $payload['error_on_requires_action']);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function enrichedMetadata(?Invoice $invoice): array
    {
        $metadata = $this->metadata;

        $metadata['lago_payment_id'] = $this->payment->id;
        $metadata['lago_payable_id'] = $this->payment->payable_id;
        $metadata['lago_payable_type'] = $this->payment->payable_type;
        $metadata['lago_customer_id'] = $this->payment->customer_id;
        $metadata['lago_organization_id'] = $this->payment->organization_id;
        $metadata['lago_billing_entity_id'] = $this->payment->customer?->billing_entity_id;

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function customerBalanceFields(): array
    {
        return [
            'payment_method_data' => ['type' => 'customer_balance'],
            'payment_method_options' => [
                'customer_balance' => [
                    'funding_type' => 'bank_transfer',
                    'bank_transfer' => $this->bankTransferType(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bankTransferType(): array
    {
        $currency = mb_strtolower($this->payment->amount_currency);

        if ($currency === 'eur') {
            return ['type' => 'eu_bank_transfer', 'eu_bank_transfer' => ['country' => $this->euBankTransferCountry()]];
        }

        return match ($currency) {
            'usd' => ['type' => 'us_bank_transfer'],
            'gbp' => ['type' => 'gb_bank_transfer'],
            'jpy' => ['type' => 'jp_bank_transfer'],
            'mxn' => ['type' => 'mx_bank_transfer'],
            default => [],
        };
    }

    /**
     * Rails: handle_eu_bank_transfer — the supported EU country comes from
     * the customer, then the billing entity; neither yields a
     * missing_country service failure.
     */
    private function euBankTransferCountry(): string
    {
        $supported = ['BE', 'DE', 'ES', 'FR', 'IE', 'NL'];

        $customer = $this->payment->customer;
        $customerCountry = mb_strtoupper((string) $customer?->country);
        $billingEntityCountry = mb_strtoupper((string) $customer?->billingEntity?->country);

        if (in_array($customerCountry, $supported, true)) {
            return $customerCountry;
        }

        if (in_array($billingEntityCountry, $supported, true)) {
            return $billingEntityCountry;
        }

        $result = static::makeResult();
        $result->serviceFailure(
            code: 'missing_country',
            message: 'No country found for customer or organization supported for EU bank transfer payload',
        )->raiseIfError();

        return '';
    }

    private function stripePaymentMethod(): ?string
    {
        $paymentMethodId = $this->payment->paymentMethod?->provider_method_id;

        if ($paymentMethodId !== null) {
            return $paymentMethodId;
        }

        // Rails: retrieve the customer's first listed payment method
        // (CheckPaymentMethodService + list_payment_methods fallback).
        return null;
    }

    private function successRedirectUrl(\App\Models\PaymentProvider $paymentProvider): string
    {
        return $paymentProvider->successRedirectUrl() ?: \App\Models\PaymentProvider::STRIPE_SUCCESS_REDIRECT_URL;
    }

    /**
     * Rails: off_session? — false for Indian customers (RBI on-session
     * requirement) and customer_balance connections; true otherwise.
     */
    private function offSession(?Invoice $invoice, array $methods): bool
    {
        if ($invoice?->customer?->country === 'IN') {
            return false;
        }

        if ($methods === ['customer_balance']) {
            return false;
        }

        return true;
    }

    private function payableHadAuthenticationError(?Invoice $invoice): bool
    {
        if ($invoice === null) {
            return false;
        }

        return Payment::query()
            ->where('payable_type', 'Invoice')
            ->where('payable_id', $invoice->id)
            ->where('error_code', \App\Models\PaymentProvider::STRIPE_NEED_3DS_ERROR_CODE)
            ->exists();
    }

    private function payable(): ?Invoice
    {
        $payable = $this->payment->payable;

        return $payable instanceof Invoice ? $payable : null;
    }

    private function prepareFailedResult(
        BaseResult $result,
        StripeError $error,
        \App\Models\PaymentProvider $paymentProvider,
        bool $reraise = false,
    ): BaseResult {
        $payablePaymentStatus = 'failed';
        $shouldRetry = false;

        if ($error->code() === \App\Models\PaymentProvider::STRIPE_AMOUNT_TOO_SMALL_ERROR_CODE) {
            // Rails: keep the invoice pending — the user can still pay manually.
            $payablePaymentStatus = 'pending';
        } elseif ($error instanceof IdempotencyError) {
            $payablePaymentStatus = 'pending';
        } elseif ($error->code() === \App\Models\PaymentProvider::STRIPE_NEED_3DS_ERROR_CODE) {
            $shouldRetry = $this->retriableAuthenticationFailure($paymentProvider, $error->code());
        }

        $result->error_message = $error->getMessage();
        $result->error_code = $error->code();
        $result->reraise = $reraise;
        $result->should_retry = $shouldRetry;

        $this->payment->status = 'failed';
        $this->payment->payable_payment_status = $payablePaymentStatus;
        $this->payment->provider_payment_id = $error->paymentIntentId;
        $this->payment->error_code = $error->code();
        $this->payment->save();

        return $result->serviceFailure(code: 'stripe_error', message: $error->getMessage(), error: $error);
    }

    /** Rails: StripeProvider#retriable_authentication_failure?. */
    private function retriableAuthenticationFailure(\App\Models\PaymentProvider $paymentProvider, ?string $errorCode): bool
    {
        if ($errorCode !== \App\Models\PaymentProvider::STRIPE_NEED_3DS_ERROR_CODE) {
            return false;
        }

        if ($paymentProvider->supports3ds() !== null) {
            return true;
        }

        $invoice = $this->payable();

        return $invoice !== null && $invoice->subscriptionGated();
    }
}
