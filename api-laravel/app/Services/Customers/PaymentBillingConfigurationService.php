<?php

declare(strict_types=1);

namespace App\Services\Customers;

use Throwable;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\PaymentProviders\FindService;
use App\Services\PaymentProviders\Stripe\Customers\CreateOrUpdateService;

use function is_array;
use function array_key_exists;

/**
 * Port of Rails' Customers::UpsertFromApiService#handle_api_billing_configuration
 * — the payment-provider branch of the customer billing configuration.
 *
 * Merge semantics:
 *  - document_locale is assigned when the key is present;
 *  - a new customer, or an existing one with no provider customer id yet
 *    and sync_with_provider / provider_customer_id given, creates the
 *    connection outright (api context: the provider slug and code are
 *    resolved and stamped on the customer first);
 *  - otherwise the update path runs: payment_provider /
 *    payment_provider_code assignments (an unknown provider slug is
 *    rejected), removing the provider discards the old connection and its
 *    payment methods, and a changed provider (or any provider customer
 *    input) recreates the connection via the provider-specific
 *    create-or-update service (Stripe: PaymentProviders\Stripe\Customers\CreateOrUpdateService).
 *
 * TODO(port): PaymentProviderCustomers::UpdateService (the Stripe-side
 * customer PATCH after a connection change) and the other providers'
 * create services (gocardless / cashfree / adyen / flutterwave / moneyhash —
 * Rails entry points: PaymentProviders::{Provider}::Customers::CreateService).
 */
class PaymentBillingConfigurationService extends BaseService
{
    public function __construct(
        private readonly Customer $customer,
        private readonly array $params,
        private readonly bool $newCustomer,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer');
        $customer = $this->customer;
        $billing = $this->params['billing_configuration'] ?? [];
        $billing = is_array($billing) ? $billing : [];

        if (array_key_exists('document_locale', $billing)) {
            $customer->document_locale = $billing['document_locale'];
        }

        if ($this->newCustomer || $this->shouldCreateBillingConfiguration($billing, $customer)) {
            $createResult = $this->createBillingConfiguration($customer, $billing);

            if ($createResult !== null && $createResult->failure()) {
                return $createResult;
            }

            $customer->save();
            $result->customer = $customer;

            return $result;
        }

        $oldProviderCustomer = $this->providerCustomer($customer);
        $oldPaymentProvider = $customer->payment_provider;

        $paymentProviderResult = $oldPaymentProvider !== null ? FindService::call(
            organizationId: $customer->organization_id,
            code: $customer->payment_provider_code,
            paymentProviderType: $oldPaymentProvider,
        ) : null;

        $oldPaymentProviderId = $paymentProviderResult?->payment_provider?->id;

        if (array_key_exists('payment_provider', $billing)) {
            $customer->payment_provider = null;

            if ($billing['payment_provider'] !== null
                && in_array($billing['payment_provider'], Customer::PAYMENT_PROVIDERS, true)) {
                $customer->payment_provider = $billing['payment_provider'];

                if (array_key_exists('payment_provider_code', $billing)) {
                    $customer->payment_provider_code = $billing['payment_provider_code'];
                }
            }
        }

        $removingProvider = $oldProviderCustomer !== null
            && array_key_exists('payment_provider', $billing)
            && ($billing['payment_provider'] === null || $billing['payment_provider'] === '');

        if ($removingProvider) {
            $customer->payment_provider_code = null;
        }

        $customer->save();

        if ($removingProvider) {
            $oldProviderCustomer->delete();
            $this->discardPaymentMethods($oldProviderCustomer->paymentMethods()->get());
        }

        if ($customer->payment_provider === null) {
            $result->customer = $customer;

            return $result;
        }

        $updateProviderCustomer = (($billing['provider_customer_id'] ?? null) !== null)
            || ($this->providerCustomer($customer)?->provider_customer_id !== null
                && $this->providerCustomer($customer)?->provider_customer_id !== '')
            || $oldProviderCustomer !== null;

        if (! $updateProviderCustomer) {
            $result->customer = $customer;

            return $result;
        }

        try {
            $this->createOrUpdateProviderCustomer($customer, $billing);

            if ($this->providerCustomer($customer)?->provider_customer_id !== null) {
                // TODO(port): PaymentProviderCustomers::UpdateService.call —
                // PATCHes the Stripe-side customer with the new attributes.
            }

            $newPaymentProvider = $this->paymentProvider($customer);
            $newPaymentProviderId = $newPaymentProvider?->id;

            if ($oldPaymentProvider !== $customer->payment_provider
                || ($oldPaymentProviderId !== null
                    && $newPaymentProviderId !== null
                    && $oldPaymentProviderId !== $newPaymentProviderId)) {
                $this->discardPaymentMethods($oldProviderCustomer->paymentMethods()->get());
            }
        } catch (Throwable $e) {
            return $result->singleValidationFailure('value_is_invalid', 'billing_configuration');
        }

        $result->customer = $customer;

        return $result;
    }

    /** Rails: create_billing_configuration. */
    private function createBillingConfiguration(Customer $customer, array $billing): ?BaseResult
    {
        if ($billing === [] || ($billing['payment_provider'] ?? null) === null) {
            return null;
        }

        $createProviderCustomer = $billing['sync_with_provider'] ?? $billing['provider_customer_id'] ?? null;

        if ($createProviderCustomer === null || $createProviderCustomer === false) {
            return null;
        }

        $customer->payment_provider = $billing['payment_provider'];

        $findResult = FindService::call(
            organizationId: $customer->organization_id,
            code: ($billing['payment_provider_code'] ?? null) ?: null,
            paymentProviderType: $customer->payment_provider,
        );

        if ($findResult->failure()) {
            return $findResult;
        }

        $customer->payment_provider_code = $findResult->payment_provider->code;
        $customer->save();

        $this->createOrUpdateProviderCustomer($customer, $billing);

        return null;
    }

    /** Rails: should_create_billing_configuration?. */
    private function shouldCreateBillingConfiguration(array $billing, Customer $customer): bool
    {
        $hasInput = (($billing['sync_with_provider'] ?? null) !== null)
            || (($billing['provider_customer_id'] ?? null) !== null);

        return $hasInput && ($this->providerCustomer($customer)?->provider_customer_id === null
            || $this->providerCustomer($customer)?->provider_customer_id === '');
    }

    /** Rails: create_or_update_provider_customer (factory on the provider slug). */
    private function createOrUpdateProviderCustomer(Customer $customer, array $billing): void
    {
        $provider = $billing['payment_provider'] ?? $customer->payment_provider;
        $resolved = $this->paymentProvider($customer);

        $async = ! (($billing['sync'] ?? null) === true);

        if ($provider === 'stripe') {
            CreateOrUpdateService::call(
                customer: $customer,
                paymentProviderId: $resolved?->id,
                params: $billing,
                async: $async,
            )->raiseIfError();

            return;
        }

        // TODO(port): the other providers' Customers::CreateService legs
        // (gocardless / cashfree / adyen / flutterwave / moneyhash).
    }

    /** Rails: customer.provider_customer for the customer's provider slug. */
    private function providerCustomer(Customer $customer): ?\App\Models\PaymentProviderCustomer
    {
        if ($customer->payment_provider === null) {
            return null;
        }

        $type = match ($customer->payment_provider) {
            'stripe' => 'PaymentProviderCustomers::StripeCustomer',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        return $customer->paymentProviderCustomers()->where('type', $type)->first();
    }

    private function paymentProvider(Customer $customer): ?\App\Models\PaymentProvider
    {
        if ($customer->payment_provider === null) {
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

    /** Rails: discard_payment_methods. */
    private function discardPaymentMethods(iterable $paymentMethods): void
    {
        foreach ($paymentMethods as $paymentMethod) {
            $paymentMethod->is_default = false;
            $paymentMethod->delete();
        }
    }
}
