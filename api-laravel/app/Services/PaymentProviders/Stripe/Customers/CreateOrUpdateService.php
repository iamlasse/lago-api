<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe\Customers;

use Throwable;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProviderCustomer;
use App\Jobs\PaymentProviders\StripeCreateCustomerJob;

/**
 * Port of Rails' PaymentProviders::Stripe::Customers::CreateService — finds
 * or builds the customer's PaymentProviderCustomers::StripeCustomer row,
 * applies the billing_configuration params (provider_customer_id,
 * sync_with_provider, provider_payment_methods — new connections default to
 * ["card"]), then:
 *  - sync_with_provider set and no provider customer id yet -> queue the
 *    Stripe customer creation (StripeCreateCustomerJob);
 *  - an existing connection just received a provider_customer_id while
 *    sync is off and setup methods are configured -> checkout URL leg
 *    (TODO(port): StripeCheckoutUrlJob — hosted checkout slice);
 *  - otherwise, when the customer has no payment methods yet, Rails
 *    enqueues FetchDefaultPaymentMethodJob (TODO(port): fetches and stores
 *    the card details).
 */
class CreateOrUpdateService extends BaseService
{
    /** StripeProviderCustomers::StripeCustomer::PAYMENT_METHODS. */
    public const PAYMENT_METHODS_WITH_SETUP = ['card', 'sepa_debit', 'us_bank_account', 'bacs_debit', 'link', 'boleto'];

    public const PAYMENT_METHODS_WITHOUT_SETUP = ['crypto', 'customer_balance'];

    public function __construct(
        private readonly Customer $customer,
        private readonly int|string|null $paymentProviderId,
        private readonly array $params = [],
        private readonly bool $async = true,
    ) {
        parent::__construct();
    }

    /** Rails' Object#present? on a settings value. */
    public static function settingPresent(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false && $value !== [];
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('provider_customer');
        $customer = $this->customer;

        try {
            $providerCustomer = PaymentProviderCustomer::query()
                ->where('customer_id', $customer->id)
                ->where('type', 'PaymentProviderCustomers::StripeCustomer')
                ->first();

            $providerCustomer ??= new PaymentProviderCustomer([
                'customer_id' => $customer->id,
                'payment_provider_id' => $this->paymentProviderId,
                'organization_id' => $customer->organization_id,
                'type' => 'PaymentProviderCustomers::StripeCustomer',
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

            $providerCustomer = $this->handleProviderPaymentMethods($providerCustomer);

            $providerCustomer->code = $providerCustomer->code ?: $providerCustomer->paymentProvider?->code;
            $providerCustomer->save();

            $result->provider_customer = $providerCustomer;

            if ($this->shouldCreateProviderCustomer($providerCustomer)) {
                $this->createCustomerOnProvider($providerCustomer);
            }

            if ($this->shouldFetchPaymentMethod($customer, $providerCustomer)) {
                // TODO(port): FetchDefaultPaymentMethodJob.perform_later —
                // fetches the default payment method's card details.
            }

            return $result;
        } catch (Throwable $e) {
            return $result->singleValidationFailure('value_already_exist', 'provider_customer_id');
        }
    }

    private function handleProviderPaymentMethods(PaymentProviderCustomer $providerCustomer): PaymentProviderCustomer
    {
        $methods = $this->params['provider_payment_methods'] ?? null;

        if ($providerCustomer->exists) {
            if ($methods !== null && $methods !== []) {
                $providerCustomer->pushToSettings('provider_payment_methods', array_values((array) $methods));
            }
        } else {
            $providerCustomer->pushToSettings(
                'provider_payment_methods',
                $methods !== null && $methods !== [] ? array_values((array) $methods) : ['card'],
            );
        }

        return $providerCustomer;
    }

    private function shouldCreateProviderCustomer(PaymentProviderCustomer $providerCustomer): bool
    {
        // Rails: !provider_customer_id? && !provider_customer_id_previously_changed? &&
        //        sync_with_provider.present?
        $sync = $providerCustomer->getFromSettings('sync_with_provider');

        return ($providerCustomer->provider_customer_id === null || $providerCustomer->provider_customer_id === '')
            && ! $providerCustomer->wasChanged('provider_customer_id')
            && self::settingPresent($sync);
    }

    private function shouldFetchPaymentMethod(Customer $customer, PaymentProviderCustomer $providerCustomer): bool
    {
        return ! $this->shouldCreateProviderCustomer($providerCustomer)
            && ! $customer->paymentMethods()->exists()
            && ($providerCustomer->provider_customer_id !== null && $providerCustomer->provider_customer_id !== '');
    }

    private function createCustomerOnProvider(PaymentProviderCustomer $providerCustomer): void
    {
        if ($this->async) {
            StripeCreateCustomerJob::dispatch($providerCustomer);

            return;
        }

        StripeCreateCustomerJob::dispatchSync($providerCustomer);
    }
}
