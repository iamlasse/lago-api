<?php

declare(strict_types=1);

namespace App\Services\Customers;

use Throwable;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\CustomerMetadata;
use Illuminate\Support\Facades\DB;
use App\Enums\FinalizeZeroAmountInvoice;
use App\Services\BillingEntities\ResolveService;
use App\Services\IntegrationCustomers\CreateOrUpdateBatchService;

use function is_array;
use function array_key_exists;

/**
 * Port of Rails' Customers::UpsertFromApiService — the API create endpoint:
 * finds the customer by external_id and updates it, or creates it.
 *
 * Merge rules (the upsert semantics): every attribute is only assigned when
 * its key is present in the params (`params.key?(:x)`) — absent keys leave
 * the stored value untouched, present-but-nil keys overwrite with nil.
 * `finalize_zero_amount_invoice` falls back to "inherit" when the key is
 * present but nil.
 *
 * Not ported (dependencies do not exist yet):
 * - The payment-provider branch of handle_api_billing_configuration is
 *   ported (PaymentBillingConfigurationService); PaymentProviderCustomers::UpdateService
 *   and the non-stripe provider legs remain TODO(port) inside it.
 * - TODO(port): RefreshInvoicesSearchTermsJob + error_details tax cleanup.
 * - TODO(port): ManageInvoiceCustomSectionsService.
 */
class UpsertFromApiService extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer');
        $organization = $this->organization;
        $params = $this->params;

        // Rails rescues FailedResult at method level, so the resolve's raise
        // is caught below like any other failure.
        $billingEntityResult = ResolveService::call(
            organization: $organization,
            billingEntityCode: $params['billing_entity_code'] ?? null,
        );

        $customer = $organization->customers()->withTrashed()->firstOrNew(
            // Rails: params[:external_id] — nil when the key is absent (the
            // model validation reports value_is_mandatory).
            ['external_id' => $params['external_id'] ?? null],
        );

        $newCustomer = ! $customer->exists;

        $originalSearchableValues = $this->slice($customer, Customer::SEARCHABLE_CUSTOMER_FIELDS);

        $shippingAddress = array_key_exists('shipping_address', $params) && is_array($params['shipping_address'])
            ? $params['shipping_address']
            : [];

        if (! $this->validMetadataCount($params['metadata'] ?? null)) {
            return $result->singleValidationFailure('invalid_count', 'metadata');
        }

        if (! $this->validFinalizeZeroAmountInvoice($params['finalize_zero_amount_invoice'] ?? null)) {
            return $result->singleValidationFailure('invalid_value', 'finalize_zero_amount_invoice');
        }

        if (! $this->validIntegrationCustomersCount($params['integration_customers'] ?? null)) {
            return $result->singleValidationFailure('invalid_count_per_integration_type', 'integration_customers');
        }

        try {
            $billingEntity = $billingEntityResult->raiseIfError()->billing_entity;

            DB::transaction(function () use (
                $customer,
                $organization,
                $params,
                $shippingAddress,
                $newCustomer,
                $billingEntity,
                $result,
            ): void {
                $originalTaxValues = [
                    'tax_identification_number' => $customer->tax_identification_number,
                    'zipcode' => $customer->zipcode,
                    'country' => $customer->country,
                ];

                $billingEntityChanged = false;

                if ($newCustomer || array_key_exists('billing_entity_code', $params)) {
                    $customer->billing_entity_id = $billingEntity->id;
                    $billingEntityChanged = ! $newCustomer && $customer->isDirty('billing_entity_id');
                }

                $this->assignParams($customer, $params, $shippingAddress);

                if ($this->revenueShareEnabled($organization) && $customer->editable()) {
                    if (array_key_exists('account_type', $params)) {
                        $customer->account_type = $params['account_type'];
                    }

                    $customer->exclude_from_dunning_campaign = $customer->partnerAccount();
                }

                $this->assignPremiumAttributes($customer, $params);

                $addressChanged = ! $newCustomer && $customer->addressChanged();

                if (array_key_exists('currency', $params)) {
                    UpdateCurrencyService::call(
                        customer: $customer,
                        currency: $params['currency'],
                        customerUpdate: true,
                    )->raiseIfError();
                }

                $errors = $customer->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $customer->save();

                // TODO(port): customer.error_details tax_error cleanup when
                // the address changed (ErrorDetail model not ported yet).
                // TODO(port): RefreshInvoicesSearchTermsJob when searchable
                // fields changed.

                $taxAttributesChanged = false;

                foreach ($originalTaxValues as $key => $value) {
                    if (array_key_exists($key, $params) && $params[$key] !== $value) {
                        $taxAttributesChanged = true;
                    }
                }

                $euTaxCodeResult = EuAutoTaxesService::call(
                    customer: $customer,
                    newRecord: $newCustomer,
                    taxAttributesChanged: $taxAttributesChanged || $billingEntityChanged,
                );

                $taxCodes = $params['tax_codes'] ?? null;

                if ($euTaxCodeResult->success()) {
                    $taxCodes = array_values(array_unique([...($taxCodes ?? []), $euTaxCodeResult->tax_code]));
                }

                // Rails: EU-managed taxes (lago_eu_*) belong to the previous
                // billing entity — when it changed and no new tax codes were
                // given, carry over the non-EU taxes.
                if ($billingEntityChanged && ! array_key_exists('tax_codes', $params)) {
                    $taxCodes = $customer->taxes()
                        ->where('code', 'not ilike', 'lago_eu%')
                        ->pluck('code')
                        ->all();
                }

                if (is_array($taxCodes)) {
                    ApplyTaxesService::call(customer: $customer, taxCodes: $taxCodes)->raiseIfError();
                }

                // TODO(port): ManageInvoiceCustomSectionsService.

                if (array_key_exists('metadata', $params) && is_array($params['metadata'])) {
                    if ($newCustomer) {
                        foreach ($params['metadata'] as $metadata) {
                            $this->createMetadata($customer, $organization, $metadata, $result)->raiseIfError();
                        }
                    } else {
                        Metadata\UpdateService::call(customer: $customer, params: $params['metadata'])
                            ->raiseIfError();
                    }
                }
            });

            // Rails: handle_api_billing_configuration always runs the issuing
            // date settings service first, then the payment-provider branch
            // (document_locale assignment + provider customer sync).
            UpdateInvoiceIssuingDateSettingsService::call(
                customer: $customer,
                params: $params,
            )->raiseIfError();

            PaymentBillingConfigurationService::call(
                customer: $customer,
                params: $params,
                newCustomer: $newCustomer,
            )->raiseIfError();

            CreateOrUpdateBatchService::call(
                integration_customers: $params['integration_customers'] ?? null,
                customer: $customer,
                new_customer: $newCustomer,
            );

            // Rails: SendWebhookJob.perform_later("customer.created" (new) /
            // "customer.updated" (existing)) — right after the integration-
            // customers batch. TODO(port): activity log entry.
            SendWebhookJob::performLater(
                $newCustomer ? 'customer.created' : 'customer.updated',
                $customer,
            );

            $result->customer = $customer;

            return $result;
        } catch (Throwable $e) {
            if ($e instanceof \App\Services\Failures\ServiceFailure) {
                // Rails: `rescue BaseService::ServiceFailure ->
                // single_validation_failure!(error_code: e.code)`.
                return $result->singleValidationFailure($e->code);
            }

            if ($e instanceof \App\Services\Failures\FailedResult) {
                return $this->embedFailure($result, $e);
            }

            if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException) {
                return $result->singleValidationFailure('value_already_exist', 'external_id');
            }

            throw $e;
        }
    }

    /**
     * Rails' attribute assignment block — only `params.key?` keys are
     * assigned; country/shipping country are upcased.
     */
    protected function assignParams(Customer $customer, array $params, array $shippingAddress): void
    {
        if (array_key_exists('name', $params)) {
            $customer->name = $params['name'];
        }
        if (array_key_exists('country', $params)) {
            $customer->country = $params['country'] !== null ? mb_strtoupper($params['country']) : null;
        }
        if (array_key_exists('address_line1', $params)) {
            $customer->address_line1 = $params['address_line1'];
        }
        if (array_key_exists('address_line2', $params)) {
            $customer->address_line2 = $params['address_line2'];
        }
        if (array_key_exists('state', $params)) {
            $customer->state = $params['state'];
        }
        if (array_key_exists('zipcode', $params)) {
            $customer->zipcode = $params['zipcode'];
        }
        if (array_key_exists('email', $params)) {
            $customer->email = $params['email'];
        }
        if (array_key_exists('city', $params)) {
            $customer->city = $params['city'];
        }
        if (array_key_exists('address_line1', $shippingAddress)) {
            $customer->shipping_address_line1 = $shippingAddress['address_line1'];
        }
        if (array_key_exists('address_line2', $shippingAddress)) {
            $customer->shipping_address_line2 = $shippingAddress['address_line2'];
        }
        if (array_key_exists('city', $shippingAddress)) {
            $customer->shipping_city = $shippingAddress['city'];
        }
        if (array_key_exists('zipcode', $shippingAddress)) {
            $customer->shipping_zipcode = $shippingAddress['zipcode'];
        }
        if (array_key_exists('state', $shippingAddress)) {
            $customer->shipping_state = $shippingAddress['state'];
        }
        if (array_key_exists('country', $shippingAddress)) {
            $customer->shipping_country = $shippingAddress['country'] !== null
                ? mb_strtoupper($shippingAddress['country'])
                : null;
        }
        if (array_key_exists('url', $params)) {
            $customer->url = $params['url'];
        }
        if (array_key_exists('phone', $params)) {
            $customer->phone = $params['phone'];
        }
        if (array_key_exists('logo_url', $params)) {
            $customer->logo_url = $params['logo_url'];
        }
        if (array_key_exists('legal_name', $params)) {
            $customer->legal_name = $params['legal_name'];
        }
        if (array_key_exists('legal_number', $params)) {
            $customer->legal_number = $params['legal_number'];
        }
        if (array_key_exists('net_payment_term', $params)) {
            $customer->net_payment_term = $params['net_payment_term'];
        }
        if (array_key_exists('external_salesforce_id', $params)) {
            $customer->external_salesforce_id = $params['external_salesforce_id'];
        }
        if (array_key_exists('finalize_zero_amount_invoice', $params)) {
            // Rails: `params[:finalize_zero_amount_invoice] || "inherit"` — a
            // present-but-nil value resets to inherit.
            $mapped = FinalizeZeroAmountInvoice::fromOption($params['finalize_zero_amount_invoice'] ?? 'inherit');
            $customer->finalize_zero_amount_invoice = $mapped;
        }
        if (array_key_exists('firstname', $params)) {
            $customer->firstname = $params['firstname'];
        }
        if (array_key_exists('lastname', $params)) {
            $customer->lastname = $params['lastname'];
        }
        if (array_key_exists('customer_type', $params)) {
            $customer->customer_type = $params['customer_type'];
        }
        if (array_key_exists('tax_identification_number', $params)) {
            $customer->tax_identification_number = $params['tax_identification_number'];
        }
    }

    /** @param mixed $value */
    protected function validFinalizeZeroAmountInvoice($value): bool
    {
        if ($value === null) {
            return true;
        }

        return in_array((string) $value, Customer::FINALIZE_ZERO_AMOUNT_INVOICE_OPTIONS, true);
    }

    /** @param mixed $metadata */
    protected function validMetadataCount($metadata): bool
    {
        if ($metadata === null || $metadata === []) {
            return true;
        }

        return count($metadata) <= CustomerMetadata::COUNT_PER_CUSTOMER;
    }

    /**
     * Rails: `valid_integration_customers_count?` — one entry max per
     * integration type.
     *
     * @param  mixed  $integrationCustomers
     */
    protected function validIntegrationCustomersCount($integrationCustomers): bool
    {
        if ($integrationCustomers === null || $integrationCustomers === []) {
            return true;
        }

        $types = [];

        foreach ($integrationCustomers as $integrationCustomer) {
            $types[] = is_array($integrationCustomer) ? ($integrationCustomer['integration_type'] ?? null) : null;
        }

        return count($types) === count(array_unique($types));
    }

    /**
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

    protected function revenueShareEnabled(Organization $organization): bool
    {
        return $this->premium()
            && in_array('revenue_share', (array) ($organization->premium_integrations ?? []), true);
    }

    protected function assignPremiumAttributes(Customer $customer, array $params): void
    {
        if (! $this->premium()) {
            return;
        }

        if (array_key_exists('timezone', $params)) {
            $customer->timezone = $params['timezone'];
        }
        if (array_key_exists('invoice_grace_period', $params)) {
            $customer->invoice_grace_period = $params['invoice_grace_period'];
        }
    }

    /**
     * Rails: `customer.slice(*SEARCHABLE_CUSTOMER_FIELDS)`.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    protected function slice(Customer $customer, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = $customer->getAttribute($field);
        }

        return $values;
    }
}
