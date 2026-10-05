<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use Throwable;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;
use App\Services\PaymentProviders\Adyen\Client;
use App\Services\PaymentProviders\Adyen\AuthenticationError;
use App\Services\PaymentMethods\FindOrCreateFromProviderService;

/**
 * Port of Rails' PaymentProviderCustomers::AdyenService:
 *  - create generates the customer's (zero-amount, 69-day) payment link and
 *    exposes it through the customer.checkout_url_generated webhook — an
 *    AuthenticationError is swallowed (the account owner already has the
 *    webhook; nothing can be done on Lago's side);
 *  - update is a no-op;
 *  - generate_checkout_url POSTs /v70/paymentLinks;
 *  - preauthorise consumes a zero-amount AUTHORISATION webhook: it stores
 *    the shopper reference + recurring detail reference as the connection's
 *    provider customer id / payment method id and creates the (default)
 *    payment method.
 */
class AdyenService extends BaseService
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const GENERATE_CHECKOUT_URL = 'generate_checkout_url';

    public const PREAUTHORISE = 'preauthorise';

    public function __construct(
        private readonly string $action,
        private readonly PaymentProviderCustomer $providerCustomer,
        private readonly ?Organization $organization = null,
        /** @var array<string, mixed>|null the AUTHORISATION notification item */
        private readonly ?array $event = null,
    ) {
        parent::__construct();
    }

    /** Rails: preauthorise entrypoint — call!(:preauthorise, organization, event). */
    public static function preauthoriseEvent(Organization $organization, array $event): BaseResult
    {
        return static::call(
            action: self::PREAUTHORISE,
            providerCustomer: new PaymentProviderCustomer(),
            organization: $organization,
            event: $event,
        );
    }

    public function execute(): BaseResult
    {
        return match ($this->action) {
            self::CREATE => $this->create(),
            self::UPDATE => $this->update(),
            self::GENERATE_CHECKOUT_URL => $this->generateCheckoutUrl(),
            self::PREAUTHORISE => $this->preauthorise(),
            default => static::makeResult(),
        };
    }

    private function create(): BaseResult
    {
        $result = static::makeResult('adyen_customer', 'checkout_url');
        $providerCustomer = $this->providerCustomer;
        $result->adyen_customer = $providerCustomer;

        if ($providerCustomer->provider_customer_id !== null && $providerCustomer->provider_customer_id !== '') {
            return $result;
        }

        try {
            $checkoutUrlResult = $this->generateCheckoutUrl();

            if ($checkoutUrlResult->failure()) {
                return $checkoutUrlResult;
            }

            $result->checkout_url = $checkoutUrlResult->checkout_url;

            return $result;
        } catch (AuthenticationError) {
            // NOTE: Authentication errors will be sent to the account owner
            // with a webhook. Since nothing can be done on Lago's side, we
            // should not raise the error.
            return $result;
        }
    }

    private function update(): BaseResult
    {
        return static::makeResult();
    }

    private function generateCheckoutUrl(): BaseResult
    {
        $result = static::makeResult('checkout_url');
        $customer = $this->providerCustomer->customer;

        $provider = $this->paymentProvider($customer);

        if ($provider === null) {
            return $result->notFoundFailure('adyen_payment_provider');
        }

        $client = new Client(
            apiKey: (string) ($provider->apiKey() ?? ''),
            environment: $provider->adyenStyleEnvironment(),
            livePrefix: (string) ($provider->livePrefix() ?? ''),
        );

        $params = [
            'reference' => 'authorization customer '.$customer->external_id,
            'amount' => [
                'value' => 0, // pre-authorization
                'currency' => $customer->currency ?: $customer->organization?->default_currency,
            ],
            'merchantAccount' => $provider->merchantAccount(),
            'returnUrl' => (string) ($provider->successRedirectUrl() ?: \App\Models\PaymentProvider::ADYEN_SUCCESS_REDIRECT_URL),
            'shopperReference' => $customer->external_id,
            'storePaymentMethodMode' => 'enabled',
            'recurringProcessingModel' => 'UnscheduledCardOnFile',
            'expiresAt' => now()->addDays(69)->toISOString(),
        ];

        if ($customer->email !== null && $customer->email !== '') {
            $params['shopperEmail'] = explode(',', mb_trim($customer->email))[0];
        }

        [$status, $response] = $client->call('post', 'paymentLinks', $params);

        if (Client::responseFailed($status)) {
            $error = Client::errorFromResponse($status, $response);

            return $result->serviceFailure(code: $error->code, message: $error->msg);
        }

        $result->checkout_url = $response['url'] ?? null;

        SendWebhookJob::performLater('customer.checkout_url_generated', $customer, [
            'checkout_url' => $result->checkout_url,
        ]);

        return $result;
    }

    /** Rails: preauthorise. */
    private function preauthorise(): BaseResult
    {
        $result = static::makeResult('adyen_customer');
        $event = $this->event ?? [];
        $organization = $this->organization;

        $shopperReference = $event['additionalData']['shopperReference']
            ?? $event['additionalData']['recurring.shopperReference']
            ?? null;
        $paymentMethodId = $event['additionalData']['recurring.recurringDetailReference'] ?? null;

        $adyenCustomer = PaymentProviderCustomer::query()
            ->where('type', 'PaymentProviderCustomers::AdyenCustomer')
            ->whereHas('customer', fn ($query) => $query
                ->where('external_id', $shopperReference)
                ->where('organization_id', $organization?->id))
            ->first();

        if ($adyenCustomer === null) {
            return $this->handleMissingCustomer($result, $shopperReference);
        }

        if (($event['success'] ?? null) === 'true') {
            $adyenCustomer->pushToSettings('payment_method_id', $paymentMethodId);
            $adyenCustomer->provider_customer_id = $shopperReference;
            $adyenCustomer->save();

            try {
                FindOrCreateFromProviderService::call(
                    customer: $adyenCustomer->customer,
                    paymentProviderCustomer: $adyenCustomer,
                    providerMethodId: $paymentMethodId,
                    setAsDefault: true,
                );
                // Race condition for multiple calls while creating the PM.
            } catch (Throwable) {
            }

            SendWebhookJob::performLater('customer.payment_provider_created', $adyenCustomer->customer);
        } else {
            SendWebhookJob::performLater('customer.payment_provider_error', $adyenCustomer->customer, [
                'provider_error' => [
                    'message' => $event['reason'] ?? '',
                    'error_code' => $event['eventCode'] ?? '',
                ],
            ]);
        }

        $result->adyen_customer = $adyenCustomer;

        return $result;
    }

    /** Rails: handle_missing_customer. */
    private function handleMissingCustomer(BaseResult $result, ?string $shopperReference): BaseResult
    {
        // NOTE: Adyen customer was not created from lago.
        if ($shopperReference === null || $shopperReference === '') {
            return $result;
        }

        // NOTE: Customer does not belong to this lago instance.
        if (! Customer::query()->where('external_id', $shopperReference)->exists()) {
            return $result;
        }

        return $result->notFoundFailure('adyen_customer');
    }

    /** Rails: payment_provider(customer). */
    private function paymentProvider(?Customer $customer): ?\App\Models\PaymentProvider
    {
        if ($customer?->payment_provider === null) {
            return null;
        }

        $findResult = \App\Services\PaymentProviders\FindService::call(
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
