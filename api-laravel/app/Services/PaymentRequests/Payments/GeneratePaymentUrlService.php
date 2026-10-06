<?php

declare(strict_types=1);

namespace App\Services\PaymentRequests\Payments;

use Throwable;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentRequest;
use App\Services\Failures\FailedResult;
use App\Services\PaymentProviders\FindService;

use function in_array;

/**
 * Port of Rails' PaymentRequests::Payments::GeneratePaymentUrlService —
 * the payment-request payment URL flow: validates the provider pair, then
 * dispatches to the provider's `generate_payment_url` arm
 * (PaymentRequests::Payments::PaymentProviders::Factory).
 *
 * Validation errors (single_validation_failure! in Rails): no linked
 * payment provider ("no_linked_payment_provider"), gocardless
 * ("invalid_payment_provider"), a succeeded request
 * ("invalid_payment_status"), a missing provider record
 * ("missing_payment_provider"), a missing provider customer when the
 * connection requires an id ("missing_payment_provider_customer"), and a
 * provider error ("payment_provider_error"). A third-party failure
 * delivers the payment_request.payment_failure webhook.
 */
class GeneratePaymentUrlService extends BaseService
{
    public const PROVIDER_GOCARDLESS = 'gocardless';

    public function __construct(
        private readonly ?PaymentRequest $payable,
    ) {
        parent::__construct();
    }

    /** Rails: PaymentRequests::Payments::PaymentProviders::Factory.for — the service class. */
    public static function serviceClass(string $paymentProvider): ?string
    {
        return match ($paymentProvider) {
            'stripe' => StripeService::class,
            'adyen' => AdyenService::class,
            'cashfree' => CashfreeService::class,
            'flutterwave' => FlutterwaveService::class,
            'gocardless' => GocardlessService::class,
            'moneyhash' => MoneyhashService::class,
            default => null,
        };
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_url');

        try {
            if ($this->payable === null) {
                return $result->notFoundFailure('payment_request');
            }

            $payable = $this->payable;
            $customer = $payable->customer;
            $provider = (string) ($customer?->payment_provider ?? '');

            if ($provider === '') {
                return $result->singleValidationFailure('no_linked_payment_provider');
            }

            if ($provider === self::PROVIDER_GOCARDLESS) {
                return $result->singleValidationFailure('invalid_payment_provider');
            }

            if ($payable->paymentSucceeded()) {
                return $result->singleValidationFailure('invalid_payment_status');
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
                    && self::requireProviderPaymentId($currentPaymentProviderCustomer))) {
                return $result->singleValidationFailure('missing_payment_provider_customer');
            }

            $paymentUrlResult = $this->generatePaymentUrl($result);

            if (($paymentUrlResult->payment_url ?? null) === null) {
                return $result->singleValidationFailure('payment_provider_error');
            }

            $result->payment_url = $paymentUrlResult->payment_url;

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
    private static function requireProviderPaymentId(\App\Models\PaymentProviderCustomer $providerCustomer): bool
    {
        return match ($providerCustomer->type) {
            default => true,
        };
    }

    /**
     * The bound generate_payment_url dispatcher — the provider service runs
     * its arm and a third-party failure additionally delivers the
     * payment_request.payment_failure webhook (Rails: rescue
     * BaseService::ThirdPartyFailure).
     */
    private function generatePaymentUrl(BaseResult $result): BaseResult
    {
        $payable = $this->payable;
        $customer = $payable->customer;
        $providerSlug = (string) $customer->payment_provider;

        $providerResult = match ($providerSlug) {
            'stripe' => StripeService::generatePaymentUrl($payable),
            'adyen' => AdyenService::generatePaymentUrl($payable),
            'cashfree' => CashfreeService::generatePaymentUrl($payable),
            'flutterwave' => FlutterwaveService::generatePaymentUrl($payable),
            default => static::makeResult('payment_url')->serviceFailure(
                code: 'unsupported_payment_provider',
                message: "Payment provider '{$providerSlug}' has no payment-request payment URL flow",
            ),
        };

        $error = $providerResult->getError();

        if ($error !== null && in_array($error::class, [\App\Services\Failures\ThirdPartyFailure::class, \App\Services\Failures\ServiceFailure::class], true)) {
            DeliverErrorWebhookService::callAsync($payable, [
                'provider_customer_id' => $customer->paymentProviderCustomers()
                    ->where('payment_provider_id', self::paymentProviderFor($customer)?->id)
                    ->first()?->provider_customer_id,
                'provider_error' => [
                    'message' => $providerResult->error_message,
                    'error_code' => $providerResult->error_code,
                ],
            ]);
        }

        return $providerResult;
    }
}
