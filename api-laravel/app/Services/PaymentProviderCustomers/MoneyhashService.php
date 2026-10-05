<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use Throwable;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Http;
use App\Models\PaymentProviderCustomer;
use App\Services\PaymentProviders\FindService;
use App\Services\PaymentMethods\DestroyService;
use App\Services\PaymentMethods\FindOrCreateFromProviderService;

/**
 * Port of Rails' PaymentProviderCustomers::MoneyhashService:
 *  - create POSTs /api/v1.1/customers/ (type / names / email / phone /
 *    tax_id / address / contact / company_name + the lago custom fields),
 *    stores the returned id, delivers the customer.payment_provider_created
 *    webhook and generates the checkout URL (an HTTP error delivers the
 *    customer.payment_provider_error webhook and yields nil — the create
 *    continues without the remote id);
 *  - generate_checkout_url POSTs /api/v1.1/payments/intent/ (amount 5.0,
 *    tokenize_card true) and exposes the embed URL through the
 *    customer.checkout_url_generated webhook (delivered synchronously —
 *    Rails' perform_now);
 *  - update_payment_method / delete_payment_method maintain the connection's
 *    stored payment_method_id and the local payment method rows from the
 *    card_token webhook events.
 */
class MoneyhashService extends BaseService
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const GENERATE_CHECKOUT_URL = 'generate_checkout_url';

    public const UPDATE_PAYMENT_METHOD = 'update_payment_method';

    public const DELETE_PAYMENT_METHOD = 'delete_payment_method';

    public function __construct(
        private readonly string $action,
        private readonly ?PaymentProviderCustomer $providerCustomer = null,
        private readonly ?string $organizationId = null,
        private readonly ?string $customerId = null,
        private readonly ?string $paymentMethodId = null,
        /** @var array<string, mixed> */
        private readonly array $metadata = [],
        /** @var array<string, mixed> */
        private readonly array $cardDetails = [],
    ) {
        parent::__construct();
    }

    /** Rails: Money.from_amount helper shared with the webhook handlers. */
    public static function amountToCents(mixed $amount): int
    {
        return \App\Support\MoneyMath::round((float) $amount * 100);
    }

    public function execute(): BaseResult
    {
        return match ($this->action) {
            self::CREATE => $this->create(),
            self::UPDATE => static::makeResult(),
            self::GENERATE_CHECKOUT_URL => $this->generateCheckoutUrl(),
            self::UPDATE_PAYMENT_METHOD => $this->updatePaymentMethod(),
            self::DELETE_PAYMENT_METHOD => $this->deletePaymentMethod(),
            default => static::makeResult(),
        };
    }

    private static function customersUrl(): string
    {
        return \App\Models\PaymentProvider::moneyhashApiBaseUrl().'/api/v1.1/customers/';
    }

    private static function checkoutUrlUrl(): string
    {
        return \App\Models\PaymentProvider::moneyhashApiBaseUrl().'/api/v1.1/payments/intent/';
    }

    private function create(): BaseResult
    {
        $result = static::makeResult('moneyhash_customer', 'checkout_url');
        $providerCustomer = $this->providerCustomer;
        $customer = $providerCustomer->customer;
        $result->moneyhash_customer = $providerCustomer;

        if ($customer === null
            || ($providerCustomer->provider_customer_id !== null && $providerCustomer->provider_customer_id !== '')) {
            return $result;
        }

        $provider = $this->paymentProvider($customer);

        if ($provider === null) {
            return $result;
        }

        $moneyhashCustomer = $this->createMoneyhashCustomer($customer, $provider, $providerCustomer);

        $providerCustomer->provider_customer_id = is_array($moneyhashCustomer) ? ($moneyhashCustomer['data']['id'] ?? '') : '';
        $providerCustomer->save();

        SendWebhookJob::performLater('customer.payment_provider_created', $customer);

        $result->moneyhash_customer = $providerCustomer;

        $checkoutUrlResult = $this->generateCheckoutUrl();

        if ($checkoutUrlResult->failure()) {
            return $checkoutUrlResult;
        }

        $result->checkout_url = $checkoutUrlResult->checkout_url;

        return $result;
    }

    /**
     * Rails: create_moneyhash_customer — an HTTP error delivers the
     * customer.payment_provider_error webhook and yields nil.
     *
     * @return array<string, mixed>|null
     */
    private function createMoneyhashCustomer(Customer $customer, \App\Models\PaymentProvider $provider, PaymentProviderCustomer $providerCustomer): ?array
    {
        /** @var array<string, mixed> $mhCustomFields */
        $mhCustomFields = $providerCustomer->mhCustomFields();

        $customerParams = array_filter([
            'type' => $customer->customer_type !== null && $customer->customer_type !== '' ? mb_strtoupper($customer->customer_type) : null,
            'first_name' => $customer->firstname,
            'last_name' => $customer->lastname,
            'email' => $customer->email,
            'phone_number' => $customer->phone,
            'tax_id' => $customer->tax_identification_number !== null ? (string) (int) $customer->tax_identification_number : null,
            'address' => implode(' ', array_filter([$customer->address_line1, $customer->address_line2], fn ($v) => (bool) $v)),
            'contact_person_name' => $customer->name ?: mb_trim(($customer->firstname ?? '').' '.($customer->lastname ?? '')) ?: null,
            'company_name' => $customer->legal_name,
            'custom_fields' => array_merge([
                'lago_mh_service' => 'PaymentProviderCustomers::MoneyhashService',
                'lago_request' => 'create_moneyhash_customer',
            ], $mhCustomFields),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $response = Http::withHeaders($this->headers($provider))
                ->post(self::customersUrl(), $customerParams)
                ->throw();

            /** @var array<string, mixed> */
            return $response->json() ?? [];
        } catch (Throwable $e) {
            $this->deliverErrorWebhook($customer, $e);

            return null;
        }
    }

    private function generateCheckoutUrl(): BaseResult
    {
        $result = static::makeResult('checkout_url');
        $customer = $this->providerCustomer->customer;
        $provider = $this->paymentProvider($customer);

        if ($provider === null) {
            return $result->notFoundFailure('moneyhash_payment_provider');
        }

        if ($this->providerCustomer === null) {
            return $result->notFoundFailure('moneyhash_customer');
        }

        /** @var array<string, mixed> $mhCustomFields */
        $mhCustomFields = $this->providerCustomer->mhCustomFields();

        $params = [
            'amount' => 5.0,
            'amount_currency' => $customer->currency ?: $customer->organization?->default_currency,
            'flow_id' => $provider->flowId(),
            'billing_data' => $this->providerCustomer->mhBillingData(),
            'customer' => $this->providerCustomer->provider_customer_id,
            'webhook_url' => $provider->webhookEndPoint(),
            'merchant_initiated' => false,
            'tokenize_card' => true,
            'payment_type' => 'UNSCHEDULED',
            'recurring_data' => ['agreement_id' => $this->providerCustomer->customer_id],
            'custom_fields' => array_merge([
                'lago_mit' => false,
                'lago_mh_service' => 'PaymentProviderCustomers::MoneyhashService',
                'lago_request' => 'generate_checkout_url',
            ], $mhCustomFields),
        ];

        try {
            $response = Http::withHeaders($this->headers($provider))
                ->post(self::checkoutUrlUrl(), $params)
                ->throw();

            $embedUrl = $response->json('data.embed_url');
        } catch (Throwable $e) {
            $this->deliverErrorWebhook($customer, $e);

            return $result->serviceFailure(code: 'http_error', message: $e->getMessage());
        }

        $result->checkout_url = $embedUrl !== null ? $embedUrl.'?lago_request=generate_checkout_url' : null;

        // Rails delivers this one synchronously (SendWebhookJob.perform_now).
        SendWebhookJob::dispatchSync('customer.checkout_url_generated', $customer, [
            'checkout_url' => $result->checkout_url,
        ]);

        return $result;
    }

    /** Rails: update_payment_method. */
    private function updatePaymentMethod(): BaseResult
    {
        $result = static::makeResult('moneyhash_customer', 'payment_method');

        $moneyhashCustomer = PaymentProviderCustomer::query()->where('customer_id', $this->customerId)->first();

        if ($moneyhashCustomer === null) {
            return $this->handleMissingCustomer($result);
        }

        $moneyhashCustomer->pushToSettings('payment_method_id', $this->paymentMethodId);
        $moneyhashCustomer->save();

        $findOrCreateResult = FindOrCreateFromProviderService::call(
            customer: $moneyhashCustomer->customer,
            paymentProviderCustomer: $moneyhashCustomer,
            providerMethodId: $this->paymentMethodId,
            params: ['provider_payment_methods' => ['card']],
            setAsDefault: true,
        );

        $result->payment_method = $findOrCreateResult->payment_method;

        if ($this->cardDetails !== [] && $result->payment_method !== null) {
            $details = $result->payment_method->details ?? [];
            $result->payment_method->details = array_merge($details, $this->cardDetails);
            $result->payment_method->save();
        }

        $result->moneyhash_customer = $moneyhashCustomer;

        return $result;
    }

    /** Rails: delete_payment_method. */
    private function deletePaymentMethod(): BaseResult
    {
        $result = static::makeResult('moneyhash_customer');

        $moneyhashCustomer = PaymentProviderCustomer::query()->where('customer_id', $this->customerId)->first();

        if ($moneyhashCustomer === null) {
            return $this->handleMissingCustomer($result);
        }

        if ($moneyhashCustomer->moneyhashPaymentMethodId() === $this->paymentMethodId) {
            $moneyhashCustomer->pushToSettings('payment_method_id', null);
            $moneyhashCustomer->save();
        }

        $paymentMethod = $moneyhashCustomer->customer?->paymentMethods()
            ->where('provider_method_id', $this->paymentMethodId)
            ->first();

        if ($paymentMethod !== null) {
            DestroyService::call(paymentMethod: $paymentMethod);
        }

        $result->moneyhash_customer = $moneyhashCustomer;

        return $result;
    }

    /**
     * Rails: handle_missing_customer — silent unless the metadata carries
     * lago_customer_id AND that customer exists in the organization.
     *
     * @param  array<string, mixed>  $x
     */
    private function handleMissingCustomer(BaseResult $result): BaseResult
    {
        if (! array_key_exists('lago_customer_id', $this->metadata)) {
            return $result;
        }

        if (! Customer::query()
            ->where('id', $this->metadata['lago_customer_id'])
            ->where('organization_id', $this->organizationId)
            ->exists()) {
            return $result;
        }

        return $result->notFoundFailure('moneyhash_customer');
    }

    /** @return array<string, string> */
    private function headers(\App\Models\PaymentProvider $provider): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-Api-Key' => (string) ($provider->apiKey() ?? ''),
        ];
    }

    private function deliverErrorWebhook(Customer $customer, Throwable $error): void
    {
        SendWebhookJob::performLater('customer.payment_provider_error', $customer, [
            'provider_error' => [
                'message' => $error->getMessage(),
                'error_code' => 'http_error',
            ],
        ]);
    }

    /** Rails: payment_provider(customer). */
    private function paymentProvider(?Customer $customer): ?\App\Models\PaymentProvider
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
