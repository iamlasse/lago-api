<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use Throwable;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;

/**
 * Shared shape of Rails' PaymentProviders::{Provider}::Customers::CreateService
 * — finds or builds the customer's provider-customer row (STI type), applies
 * the billing_configuration params, then either queues the provider-side
 * customer creation (sync_with_provider on, no provider customer id yet) or,
 * for a fresh provider customer id on an existing connection with sync off,
 * generates the checkout URL (each via the provider's job).
 *
 * Cashfree and Flutterwave override the sync hooks: they keep the local row
 * only.
 */
abstract class AbstractCustomersCreateService extends BaseService
{
    public function __construct(
        private readonly Customer $customer,
        private readonly int|string|null $paymentProviderId,
        /** @var array<string, mixed> */
        private readonly array $params = [],
        private readonly bool $async = true,
    ) {
        parent::__construct();
    }

    /** The STI type stored in payment_provider_customers.type. */
    abstract protected function providerCustomerType(): string;

    public function execute(): BaseResult
    {
        $result = static::makeResult('provider_customer');
        $customer = $this->customer;
        $type = $this->providerCustomerType();

        try {
            $providerCustomer = PaymentProviderCustomer::query()
                ->where('customer_id', $customer->id)
                ->where('type', $type)
                ->first();

            $providerCustomer ??= new PaymentProviderCustomer([
                'customer_id' => $customer->id,
                'payment_provider_id' => $this->paymentProviderId,
                'organization_id' => $customer->organization_id,
                'type' => $type,
            ]);

            if (array_key_exists('provider_customer_id', $this->params)) {
                $providerCustomer->provider_customer_id = $this->params['provider_customer_id'] !== null
                    ? (string) $this->params['provider_customer_id']
                    : null;
            }

            if (array_key_exists('sync_with_provider', $this->params)) {
                $providerCustomer->pushToSettings(
                    'sync_with_provider',
                    $this->params['sync_with_provider'] !== null
                        ? (bool) $this->params['sync_with_provider']
                        : null,
                );
            }

            $providerCustomer->code = $providerCustomer->code ?: $providerCustomer->paymentProvider?->code;
            $providerCustomer->save();

            $result->provider_customer = $providerCustomer;

            if ($this->shouldCreateProviderCustomer($providerCustomer)) {
                $this->createCustomerOnProvider($providerCustomer, $this->async);
            } elseif ($this->shouldGenerateCheckoutUrl($providerCustomer)) {
                $this->generateProviderCheckoutUrl($providerCustomer, $this->async);
            }

            return $result;
        } catch (Throwable $e) {
            return $result->singleValidationFailure('value_already_exist', 'provider_customer_id');
        }
    }

    /** Queues (or runs, when not async) the provider-side customer creation. */
    protected function createOnProvider(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        // Providers without a remote customer record (cashfree, flutterwave)
        // never reach this — see shouldCreateProviderCustomer.
    }

    /** Queues (or runs, when not async) the checkout URL generation. */
    protected function generateCheckoutUrl(PaymentProviderCustomer $providerCustomer, bool $async): void {}

    /** Rails' Object#present? on a settings value. */
    protected function settingPresent(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false && $value !== [];
    }

    /**
     * Rails: should_create_provider_customer? — the customer does not exist
     * on the provider, the id was not just removed, and sync_with_provider
     * is set.
     */
    protected function shouldCreateProviderCustomer(PaymentProviderCustomer $providerCustomer): bool
    {
        $sync = $providerCustomer->getFromSettings('sync_with_provider');

        return ($providerCustomer->provider_customer_id === null || $providerCustomer->provider_customer_id === '')
            && ! $providerCustomer->wasChanged('provider_customer_id')
            && $this->settingPresent($sync);
    }

    /**
     * Rails: should_generate_checkout_url? — an existing (not just created)
     * connection that just received a provider customer id with sync off.
     */
    protected function shouldGenerateCheckoutUrl(PaymentProviderCustomer $providerCustomer): bool
    {
        return ! $providerCustomer->wasRecentlyCreated
            && $providerCustomer->wasChanged('provider_customer_id')
            && $providerCustomer->provider_customer_id !== null
            && $providerCustomer->provider_customer_id !== ''
            && ! $this->settingPresent($providerCustomer->getFromSettings('sync_with_provider'));
    }

    protected function params(): array
    {
        return $this->params;
    }

    protected function customer(): Customer
    {
        return $this->customer;
    }

    protected function async(): bool
    {
        return $this->async;
    }

    private function createCustomerOnProvider(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            $this->createOnProvider($providerCustomer, true);

            return;
        }

        $this->createOnProvider($providerCustomer, false);
    }

    private function generateProviderCheckoutUrl(PaymentProviderCustomer $providerCustomer, bool $async): void
    {
        if ($async) {
            $this->generateCheckoutUrl($providerCustomer, true);

            return;
        }

        $this->generateCheckoutUrl($providerCustomer, false);
    }
}
