<?php

declare(strict_types=1);

namespace App\Services\PaymentProviderCustomers;

use Throwable;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\PaymentProviders\FindService;
use App\Services\PaymentProviders\Stripe\Client;
use App\Services\PaymentProviders\Stripe\StripeError;

/**
 * Port of Rails' PaymentProviderCustomers::StripeService — the Stripe-side
 * customer sync. `create` POSTs /v1/customers (idempotency key
 * "{customer.id}-{customer.updated_at.to_i}") and stores the returned id on
 * the provider customer; `update` PATCHes the remote customer with the
 * current Lago attributes.
 *
 * Error mapping: InvalidRequestError / PermissionError -> third_party
 * failure + customer.payment_provider_error webhook; AuthenticationError ->
 * unauthorized failure + webhook ("Stripe authentication failed. …").
 */
class StripeService extends BaseService
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const GENERATE_CHECKOUT_URL = 'generate_checkout_url';

    public function __construct(
        private readonly string $action,
        private readonly \App\Models\PaymentProviderCustomer $providerCustomer,
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

    /**
     * Rails: `payment_provider(customer)` (Customers::PaymentProviderFinder)
     * — resolves the org's provider for the customer's payment_provider slug;
     * a missing provider resolves to nil.
     */
    public function paymentProvider(Customer $customer): ?\App\Models\PaymentProvider
    {
        if ($customer->payment_provider === null) {
            return null;
        }

        $findResult = FindService::call(
            organizationId: $customer->organization_id,
            code: $customer->payment_provider_code,
            paymentProviderType: $customer->payment_provider,
        );

        // Rails: return nil when the error code is payment_provider_not_found.
        $error = $findResult->getError();

        if ($error instanceof \App\Services\Failures\ServiceFailure && $error->code === 'payment_provider_not_found') {
            return null;
        }

        try {
            $findResult->raiseIfError();
        } catch (Throwable) {
            return null;
        }

        return $findResult->payment_provider;
    }

    /** Rails: #generate_checkout_url — the Stripe Checkout setup session. */
    private function generateCheckoutUrl(): BaseResult
    {
        $result = static::makeResult('checkout_url');
        $providerCustomer = $this->providerCustomer;
        $customer = $providerCustomer->customer;

        // NOTE: Customer is nil when deleted.
        if ($customer === null) {
            return $result;
        }

        $provider = $this->paymentProvider($customer);

        // Rails: a provider without webhook endpoints answers a bare success
        // (the URL would never be announced).
        if ($provider !== null
            && $customer->organization?->webhookEndpoints()->count() === 0) {
            return $result;
        }

        if ($providerCustomer->providerPaymentMethodsRequireSetup() === false) {
            return $result->singleValidationFailure(
                'no_payment_methods_to_setup_available',
                field: 'provider_payment_methods',
            );
        }

        $client = new Client((string) $provider->secretKey());

        $params = [
            'success_url' => ($provider->successRedirectUrl() ?: \App\Models\PaymentProvider::STRIPE_SUCCESS_REDIRECT_URL),
            'mode' => 'setup',
            'payment_method_types' => $providerCustomer->providerPaymentMethodsWithSetup(),
            'customer' => $providerCustomer->provider_customer_id,
        ];

        if ($provider->requireTermsOfServiceConsent()) {
            $params['consent_collection'] = ['terms_of_service' => 'required'];
        }

        try {
            $session = $client->call('post', '/v1/checkout/sessions', $params);
        } catch (StripeError $e) {
            $this->deliverErrorWebhook($customer, $e);

            if ($e instanceof \App\Services\PaymentProviders\Stripe\AuthenticationError) {
                return $result->unauthorizedFailure('Stripe authentication failed. '.$e->getMessage());
            }

            return $result->thirdPartyFailure('Stripe', (string) $e->code(), $e->getMessage());
        }

        $result->checkout_url = $session['url'] ?? null;

        // NOTE: the mutation path passes send_webhook: false in Rails; the
        // webhook only fires from the background flow.

        return $result;
    }

    /** Rails: #create — POST /v1/customers for a connection without an id. */
    private function create(): BaseResult
    {
        $result = static::makeResult('stripe_customer');
        $providerCustomer = $this->providerCustomer;
        $customer = $providerCustomer->customer;

        $result->stripe_customer = $providerCustomer;

        if ($customer === null) {
            return $result;
        }

        $provider = $this->paymentProvider($customer);

        if ($provider === null
            || ($providerCustomer->provider_customer_id !== null && $providerCustomer->provider_customer_id !== '')) {
            return $result;
        }

        $client = new Client(
            (string) $provider->secretKey(),
            idempotencyKey: $customer->id.'-'.($customer->updated_at?->timestamp ?? 0),
        );

        try {
            $stripeCustomer = $client->call('post', '/v1/customers', $this->customerPayload($customer));
        } catch (StripeError $e) {
            $this->deliverErrorWebhook($customer, $e);

            return $result;
        }

        $providerCustomer->provider_customer_id = $stripeCustomer['id'] ?? null;
        $providerCustomer->save();

        $result->stripe_customer = $providerCustomer;

        SendWebhookJob::performLater('customer.payment_provider_created', $customer);

        // TODO(port): StripeCheckoutUrlJob when payment methods require setup,
        // StripeSyncFundingInstructionsJob for customer_balance connections.

        return $result;
    }

    /** Rails: #update — PATCH /v1/customers/{id} with the current attributes. */
    private function update(): BaseResult
    {
        $result = static::makeResult();
        $providerCustomer = $this->providerCustomer;
        $customer = $providerCustomer->customer;

        $provider = $customer !== null ? $this->paymentProvider($customer) : null;

        if ($provider === null
            || $customer === null
            || $providerCustomer->provider_customer_id === null
            || $providerCustomer->provider_customer_id === '') {
            return $result;
        }

        $client = new Client((string) $provider->secretKey());

        try {
            $client->call('post', '/v1/customers/'.$providerCustomer->provider_customer_id, $this->customerPayload($customer));
        } catch (StripeError $e) {
            $this->deliverErrorWebhook($customer, $e);

            return $result->thirdPartyFailure('Stripe', (string) $e->code(), $e->getMessage());
        }

        return $result;
    }

    /**
     * Rails: #stripe_create_payload — the Customer create/update body.
     *
     * @return array<string, mixed>
     */
    private function customerPayload(Customer $customer): array
    {
        return [
            'address' => [
                'city' => $customer->city,
                'country' => $customer->country,
                'line1' => $customer->address_line1,
                'line2' => $customer->address_line2,
                'postal_code' => $customer->zipcode,
                'state' => $customer->state,
            ],
            'email' => $customer->email !== null
                ? explode(',', mb_trim((string) $customer->email))[0]
                : null,
            'name' => $customer->name !== null && $customer->name !== ''
                ? $customer->name
                : mb_trim(($customer->firstname ?? '').' '.($customer->lastname ?? '')),
            'metadata' => [
                'lago_customer_id' => $customer->id,
                'customer_id' => $customer->external_id,
            ],
            'phone' => $customer->phone,
        ];
    }

    private function deliverErrorWebhook(Customer $customer, StripeError $error): void
    {
        SendWebhookJob::performLater('customer.payment_provider_error', $customer, [
            'provider_error' => [
                'message' => $error->getMessage(),
                'error_code' => $error->code(),
            ],
        ]);
    }
}
