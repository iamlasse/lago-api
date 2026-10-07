<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;
use App\Services\PaymentProviders\Gocardless\Client;
use App\Jobs\PaymentProviders\GocardlessCheckoutUrlJob;
use App\Services\PaymentProviders\Gocardless\GoCardlessError;

/**
 * Port of Rails' PaymentProviderCustomers::GocardlessService:
 *  - create POSTs /customers (email / company_name / given_name /
 *    family_name, compacted), stores the id, delivers the
 *    customer.payment_provider_created webhook and queues the checkout-url
 *    job (API errors deliver the customer.payment_provider_error webhook
 *    and re-raise);
 *  - update is a no-op;
 *  - generate_checkout_url creates a billing request (bacs mandate) + a
 *    billing request flow and exposes the authorisation URL through the
 *    customer.checkout_url_generated webhook.
 */
class GocardlessService extends BaseService
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const GENERATE_CHECKOUT_URL = 'generate_checkout_url';

    public function __construct(
        private readonly string $action,
        private readonly PaymentProviderCustomer $providerCustomer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        return match ($this->action) {
            self::CREATE => $this->create(),
            self::UPDATE => $this->update(),
            self::GENERATE_CHECKOUT_URL => $this->generateCheckoutUrl(),
            default => static::makeResult(),
        };
    }

    private function create(): BaseResult
    {
        $result = static::makeResult('gocardless_customer');
        $providerCustomer = $this->providerCustomer;
        $customer = $providerCustomer->customer;
        $result->gocardless_customer = $providerCustomer;

        if ($customer === null
            || ($providerCustomer->provider_customer_id !== null && $providerCustomer->provider_customer_id !== '')) {
            return $result;
        }

        $provider = $this->paymentProvider($customer);

        if ($provider === null) {
            return $result;
        }

        $client = new Client(
            accessToken: (string) ($provider->accessToken() ?? ''),
            environment: $provider->gocardlessEnvironment(),
        );

        $customerParams = array_filter([
            'email' => $customer->email !== null ? explode(',', mb_trim($customer->email))[0] : null,
            'company_name' => $customer->name ?: null,
            'given_name' => $customer->firstname ?: null,
            'family_name' => $customer->lastname ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            [$status, $response] = $client->call('post', '/customers', $customerParams);

            if ($status >= 400) {
                throw new GoCardlessError('GoCardless customer creation failed');
            }

            $providerCustomer->provider_customer_id = $response['customers']['id'] ?? null;
            $providerCustomer->save();
        } catch (GoCardlessError $e) {
            $this->deliverErrorWebhook($customer, $e);

            throw $e;
        }

        $this->deliverSuccessWebhook($customer);

        dispatch(new \App\Jobs\PaymentProviders\GocardlessCheckoutUrlJob($providerCustomer));

        $result->gocardless_customer = $providerCustomer;

        return $result;
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
            return $result;
        }

        $client = new Client(
            accessToken: (string) ($provider->accessToken() ?? ''),
            environment: $provider->gocardlessEnvironment(),
        );

        $successRedirectUrl = (string) ($provider->successRedirectUrl() ?: \App\Models\PaymentProvider::GOCARDLESS_SUCCESS_REDIRECT_URL);

        try {
            [$status, $billingRequest] = $client->call('post', '/billing_requests', [
                'mandate_request' => ['scheme' => 'bacs'],
                'links' => ['customer' => $this->providerCustomer->provider_customer_id],
            ]);

            if ($status >= 400) {
                throw new GoCardlessError('GoCardless billing request creation failed');
            }

            [, $billingRequestFlow] = $client->call('post', '/billing_request_flows', [
                'redirect_uri' => $successRedirectUrl,
                'exit_uri' => $successRedirectUrl,
                'links' => ['billing_request' => $billingRequest['billing_requests']['id'] ?? null],
            ]);

            $result->checkout_url = $billingRequestFlow['billing_request_flows']['authorisation_url'] ?? null;
        } catch (GoCardlessError $e) {
            $this->deliverErrorWebhook($customer, $e);

            throw $e;
        }

        SendWebhookJob::performLater('customer.checkout_url_generated', $customer, [
            'checkout_url' => $result->checkout_url,
        ]);

        return $result;
    }

    private function deliverSuccessWebhook(Customer $customer): void
    {
        SendWebhookJob::performLater('customer.payment_provider_created', $customer);
    }

    private function deliverErrorWebhook(Customer $customer, GoCardlessError $error): void
    {
        SendWebhookJob::performLater('customer.payment_provider_error', $customer, [
            'provider_error' => [
                'message' => $error->getMessage(),
                'error_code' => $error->code,
            ],
        ]);
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
