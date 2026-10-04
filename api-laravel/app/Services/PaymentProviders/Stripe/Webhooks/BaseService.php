<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe\Webhooks;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Values\StripePayment;
use App\Services\BaseService as RootBaseService;

/**
 * Port of Rails' PaymentProviders::Stripe::Webhooks::BaseService — shared
 * plumbing for the Stripe event handlers: the organization, the decoded
 * event object (event.data.object), and the metadata-based customer/
 * payable resolution.
 *
 * The StripePayment normalization (Rails:
 * StripeProvider::StripePayment.new(id:, status:, metadata:, error_code:))
 * extracts last_payment_error.code from the raw event object.
 *
 * @param  array<string, mixed>  $event
 */
abstract class BaseService extends RootBaseService
{
    public function __construct(
        protected readonly Organization $organization,
        /** @var array<string, mixed> */
        protected readonly array $event,
    ) {
        parent::__construct();
    }

    /** @return array<string, mixed> */
    protected function dataObject(): array
    {
        $object = $this->event['data']['object'] ?? [];

        return is_array($object) ? $object : [];
    }

    /** @return array<string, mixed> */
    protected function metadata(): array
    {
        $metadata = $this->dataObject()['metadata'] ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    protected function metadataValue(string $key): mixed
    {
        return $this->metadata()[$key] ?? null;
    }

    /** Rails: handle_missing_customer + metadata_does_not_match_lago_customer?. */
    protected function handleMissingCustomer(BaseResult $result): BaseResult
    {
        if ($this->stripeCustomerCreatedOutsideLago()) {
            return $result;
        }

        $lagoCustomer = \App\Models\Customer::query()
            ->where('id', $this->metadataValue('lago_customer_id'))
            ->where('organization_id', $this->organization->id)
            ->first();

        if ($lagoCustomer === null || $lagoCustomer->paymentProviderCustomers()->exists()) {
            return $result->notFoundFailure('stripe_customer');
        }

        return $result;
    }

    protected function stripeCustomerCreatedOutsideLago(): bool
    {
        $metadata = $this->metadata();

        return $metadata === [] || ! array_key_exists('lago_customer_id', $metadata);
    }

    protected function stripePayment(): StripePayment
    {
        $object = $this->dataObject();
        $lastPaymentErrorCode = $object['last_payment_error']['code'] ?? null;

        return new StripePayment(
            id: (string) ($object['id'] ?? ''),
            status: (string) ($object['status'] ?? ''),
            metadata: $this->metadata(),
            errorCode: $lastPaymentErrorCode !== null ? (string) $lastPaymentErrorCode : null,
        );
    }

    /**
     * Rails: update_payment_status! — routes to the payable-type service
     * (invoices for invoices, payment requests otherwise).
     */
    protected function updatePaymentStatus(string $status, BaseResult $result): BaseResult
    {
        $payableType = $this->metadataValue('lago_payable_type') ?? 'Invoice';

        if ($payableType === 'PaymentRequest') {
            return \App\Services\PaymentRequests\Payments\StripeService::updatePaymentStatus(
                organizationId: $this->organization->id,
                status: $status,
                stripePayment: $this->stripePayment(),
                amountCents: isset($this->dataObject()['amount']) ? (int) $this->dataObject()['amount'] : null,
            );
        }

        return \App\Services\Invoices\Payments\StripeService::updatePaymentStatus(
            organizationId: $this->organization->id,
            status: $status,
            stripePayment: $this->stripePayment(),
            amountCents: isset($this->dataObject()['amount']) ? (int) $this->dataObject()['amount'] : null,
        );
    }
}
