<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless\Webhooks;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;
use App\Services\PaymentProviders\Gocardless\Client;
use App\Services\PaymentProviders\Gocardless\GoCardlessError;
use App\Services\PaymentMethods\FindOrCreateFromProviderService;

/**
 * Port of Rails' Gocardless::Webhooks::MandateCreatedService — fetches the
 * mandate from the API (a failure yields a silent return), finds the local
 * gocardless provider customer by the mandate's customer reference, stores
 * the mandate id, and creates the customer's (default) payment method for
 * it.
 */
class MandateCreatedService extends BaseService
{
    public function __construct(
        private readonly PaymentProvider $paymentProvider,
        private readonly string $mandateId,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_method');

        $mandate = $this->fetchMandate();

        if ($mandate === null) {
            return $result;
        }

        $gocardlessCustomer = $this->paymentProvider->paymentProviderCustomers()
            ->where('type', 'PaymentProviderCustomers::GocardlessCustomer')
            ->where('provider_customer_id', $mandate['links']['customer'] ?? null)
            ->first();

        if ($gocardlessCustomer === null) {
            return $result;
        }

        $gocardlessCustomer->pushToSettings('provider_mandate_id', $mandate['id']);
        $gocardlessCustomer->save();

        $result->payment_method = FindOrCreateFromProviderService::call(
            customer: $gocardlessCustomer->customer,
            paymentProviderCustomer: $gocardlessCustomer,
            providerMethodId: $mandate['id'],
            params: [
                'provider_payment_methods' => $gocardlessCustomer->providerPaymentMethods(),
            ],
            setAsDefault: true,
        )->payment_method;

        return $result;
    }

    /** Rails: fetch_mandate — an API error returns nil (silent). */
    private function fetchMandate(): ?array
    {
        $client = new Client(
            accessToken: (string) ($this->paymentProvider->accessToken() ?? ''),
            environment: $this->paymentProvider->gocardlessEnvironment(),
        );

        try {
            [$status, $response] = $client->call('get', '/mandates/'.$this->mandateId);
        } catch (GoCardlessError) {
            return null;
        }

        return $response['mandates'] ?? null;
    }
}
