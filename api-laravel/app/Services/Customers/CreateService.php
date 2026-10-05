<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Enums\FinalizeZeroAmountInvoice;
use App\Services\BillingEntities\ResolveService;
use App\Services\IntegrationCustomers\CreateOrUpdateBatchService;

use function is_array;
use function array_key_exists;

/**
 * Port of Rails' Customers::CreateService — creates a new customer in
 * request scope.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): payment provider customers (PaymentProviderCustomers::*,
 *   PaymentProviders::FindService) — `provider_customer` /
 *   `payment_provider_customers` args are accepted but ignored.
 * - TODO(port): activity log middleware.
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Organization $organization,
        private readonly array $args,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer');
        $organization = $this->organization;
        $args = $this->args;

        if ($organization === null) {
            return $result->notFoundFailure('organization');
        }

        try {
            $billingEntity = ResolveService::call(
                organization: $organization,
                billingEntityCode: $args['billing_entity_code'] ?? null,
            )->raiseIfError()->billing_entity;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        }

        $billingConfiguration = is_array($args['billing_configuration'] ?? null) ? $args['billing_configuration'] : [];
        $shippingAddress = is_array($args['shipping_address'] ?? null) ? $args['shipping_address'] : [];

        if (! $this->validMetadataCount($args['metadata'] ?? null)) {
            return $result->singleValidationFailure('invalid_count', 'metadata');
        }

        $customer = new Customer([
            'organization_id' => $organization->id,
            'billing_entity_id' => $billingEntity->id,
            'external_id' => $args['external_id'] ?? null,
            'name' => $args['name'] ?? null,
            'country' => isset($args['country']) && $args['country'] !== null ? mb_strtoupper($args['country']) : null,
            'address_line1' => $args['address_line1'] ?? null,
            'address_line2' => $args['address_line2'] ?? null,
            'state' => $args['state'] ?? null,
            'zipcode' => $args['zipcode'] ?? null,
            'shipping_address_line1' => $shippingAddress['address_line1'] ?? null,
            'shipping_address_line2' => $shippingAddress['address_line2'] ?? null,
            'shipping_country' => isset($shippingAddress['country']) && $shippingAddress['country'] !== null
                ? mb_strtoupper($shippingAddress['country'])
                : null,
            'shipping_state' => $shippingAddress['state'] ?? null,
            'shipping_zipcode' => $shippingAddress['zipcode'] ?? null,
            'shipping_city' => $shippingAddress['city'] ?? null,
            'email' => $args['email'] ?? null,
            'city' => $args['city'] ?? null,
            'url' => $args['url'] ?? null,
            'phone' => $args['phone'] ?? null,
            'logo_url' => $args['logo_url'] ?? null,
            'legal_name' => $args['legal_name'] ?? null,
            'legal_number' => $args['legal_number'] ?? null,
            'net_payment_term' => $args['net_payment_term'] ?? null,
            'external_salesforce_id' => $args['external_salesforce_id'] ?? null,
            'payment_provider' => $args['payment_provider'] ?? null,
            'payment_provider_code' => $args['payment_provider_code'] ?? null,
            'currency' => $args['currency'] ?? null,
            'document_locale' => $billingConfiguration['document_locale'] ?? null,
            'subscription_invoice_issuing_date_anchor' => $billingConfiguration['subscription_invoice_issuing_date_anchor'] ?? null,
            'subscription_invoice_issuing_date_adjustment' => $billingConfiguration['subscription_invoice_issuing_date_adjustment'] ?? null,
            'tax_identification_number' => $args['tax_identification_number'] ?? null,
            'firstname' => $args['firstname'] ?? null,
            'lastname' => $args['lastname'] ?? null,
            'customer_type' => $args['customer_type'] ?? null,
        ]);

        if ($this->revenueShareEnabled($organization)) {
            if (array_key_exists('account_type', $args)) {
                $customer->account_type = $args['account_type'];
            }

            $customer->exclude_from_dunning_campaign = $customer->partnerAccount();
        }

        if (array_key_exists('finalize_zero_amount_invoice', $args)) {
            $mapped = FinalizeZeroAmountInvoice::fromOption($args['finalize_zero_amount_invoice']);

            if ($mapped === null) {
                $result->validationFailure(['finalize_zero_amount_invoice' => ['value_is_invalid']])->raiseIfError();
            }

            $customer->finalize_zero_amount_invoice = $mapped;
        }

        $this->assignPremiumAttributes($customer, $args);

        try {
            DB::transaction(function () use ($customer, $organization, $args, $result): void {
                $errors = $customer->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $customer->save();

                $euTaxCodeResult = EuAutoTaxesService::call(
                    customer: $customer,
                    newRecord: true,
                    taxAttributesChanged: true,
                );

                $taxCodes = $args['tax_codes'] ?? [];

                if ($euTaxCodeResult->success()) {
                    $taxCodes = array_values(array_unique([...$taxCodes, $euTaxCodeResult->tax_code]));
                }

                if ($taxCodes !== []) {
                    ApplyTaxesService::call(customer: $customer, taxCodes: $taxCodes)->raiseIfError();
                }

                foreach ($args['metadata'] ?? [] as $metadata) {
                    $this->createMetadata($customer, $organization, $metadata, $result)->raiseIfError();
                }
            });

            // TODO(port): create_billing_configuration — payment provider
            // customer records (Stripe/Gocardless/…) are a later milestone.
            CreateOrUpdateBatchService::call(
                integration_customers: $args['integration_customers'] ?? null,
                customer: $customer,
                new_customer: true,
            );

            // Rails: SendWebhookJob.perform_later("customer.created", customer)
            // — right after the integration-customers batch.
            SendWebhookJob::performLater('customer.created', $customer);

            $result->customer = $customer;

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `create_metadata` — customer.metadata.create!; the failure
     * (if any) is embedded into the result.
     *
     * @param  mixed  $args
     */
    protected function createMetadata(Customer $customer, Organization $organization, $args, BaseResult $result): BaseResult
    {
        $args = is_array($args) ? $args : [];

        $metadata = $customer->metadata()->make([
            'organization_id' => $organization->id,
            'key' => $args['key'] ?? null,
            'value' => $args['value'] ?? null,
            'display_in_invoice' => $args['display_in_invoice'] ?? false,
        ]);

        $errors = $metadata->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $metadata->save();

        return $result;
    }

    protected function validMetadataCount($metadata): bool
    {
        if ($metadata === null || $metadata === []) {
            return true;
        }

        return count($metadata) <= \App\Models\CustomerMetadata::COUNT_PER_CUSTOMER;
    }

    /** Rails: `organization.revenue_share_enabled?` (License-gated premium). */
    protected function revenueShareEnabled(Organization $organization): bool
    {
        return $this->premium()
            && in_array('revenue_share', (array) ($organization->premium_integrations ?? []), true);
    }

    protected function assignPremiumAttributes(Customer $customer, array $args): void
    {
        if (! $this->premium()) {
            return;
        }

        if (array_key_exists('timezone', $args)) {
            $customer->timezone = $args['timezone'];
        }
        if (array_key_exists('invoice_grace_period', $args)) {
            $customer->invoice_grace_period = $args['invoice_grace_period'];
        }
    }
}
