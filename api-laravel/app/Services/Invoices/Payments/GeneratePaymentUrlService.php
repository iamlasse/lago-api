<?php

declare(strict_types=1);

namespace App\Services\Invoices\Payments;

use Throwable;
use App\Models\Invoice;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\FailedResult;
use App\Services\PaymentIntents\FetchService;
use App\Services\PaymentProviders\FindService;

/**
 * Port of Rails' Invoices::Payments::GeneratePaymentUrlService — the POST
 * /invoices/:id/payment_url flow: validates the invoice / provider pair,
 * fetches (or creates) the hosted-checkout payment intent, and returns its
 * payment URL.
 *
 * Validation errors (single_validation_failure! in Rails): a missing
 * invoice, no linked payment provider ("no_linked_payment_provider"),
 * gocardless ("invalid_payment_provider"), a succeeded / voided / draft
 * invoice ("invalid_invoice_status_or_payment_status"), a missing provider
 * record ("missing_payment_provider") or a missing provider customer when
 * the connection requires an id ("missing_payment_provider_customer").
 */
class GeneratePaymentUrlService extends BaseService
{
    public function __construct(
        private readonly ?Invoice $invoice,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_url');

        try {
            if ($this->invoice === null) {
                return $result->notFoundFailure('invoice');
            }

            $customer = $this->invoice->customer;
            $provider = $customer?->payment_provider;

            if ($provider === null || $provider === '') {
                return $result->singleValidationFailure('no_linked_payment_provider');
            }

            if ($provider === 'gocardless') {
                return $result->singleValidationFailure('invalid_payment_provider');
            }

            if ($this->invoice->paymentSucceeded() || $this->invoice->isVoided() || $this->invoice->isDraft()) {
                return $result->singleValidationFailure('invalid_invoice_status_or_payment_status');
            }

            $currentPaymentProvider = self::paymentProviderFor($customer);

            if ($currentPaymentProvider === null) {
                return $result->singleValidationFailure('missing_payment_provider');
            }

            $currentPaymentProviderCustomer = $customer->paymentProviderCustomers()
                ->where('payment_provider_id', $currentPaymentProvider->id)
                ->first();

            $providerCustomerId = $currentPaymentProviderCustomer?->provider_customer_id;

            if ($currentPaymentProviderCustomer === null
                || (($providerCustomerId === null || $providerCustomerId === '')
                    && $this->requireProviderPaymentId($currentPaymentProviderCustomer))) {
                return $result->singleValidationFailure('missing_payment_provider_customer');
            }

            $paymentIntent = FetchService::callBang(invoice: $this->invoice)->payment_intent;

            $result->payment_url = $paymentIntent->payment_url;

            return $result;
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /** Rails: Customers::PaymentProviderFinder#payment_provider. */
    private static function paymentProviderFor(?\App\Models\Customer $customer): ?\App\Models\PaymentProvider
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

    /** Rails: BaseCustomer#require_provider_payment_id? (true for every ported connection type). */
    private function requireProviderPaymentId(\App\Models\PaymentProviderCustomer $providerCustomer): bool
    {
        return match ($providerCustomer->type) {
            default => true,
        };
    }
}
