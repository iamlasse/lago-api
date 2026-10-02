<?php

declare(strict_types=1);

namespace App\Services\Customers;

use Throwable;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Enums\FinalizeZeroAmountInvoice;

use function is_array;
use function array_key_exists;

/**
 * Port of Rails' Customers::UpdateService — updates an existing customer
 * from GraphQL/internal flows (customer + args).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): payment provider customers (discard + recreate flows).
 * - TODO(port): dunning campaign assignment (auto_dunning premium).
 * - TODO(port): ManageInvoiceCustomSectionsService.
 * - TODO(port): RefreshInvoicesSearchTermsJob + error_details tax cleanup.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
        private readonly array $args,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('customer');
        $customer = $this->customer;
        $args = $this->args;

        if ($customer === null) {
            return $result->notFoundFailure('customer');
        }

        if (! $this->validMetadataCount($args['metadata'] ?? null)) {
            return $result->singleValidationFailure('invalid_count', 'metadata');
        }

        $originalTaxValues = [
            'tax_identification_number' => $customer->tax_identification_number,
            'zipcode' => $customer->zipcode,
            'country' => $customer->country,
        ];

        $originalSearchableValues = $this->slice($customer, Customer::SEARCHABLE_CUSTOMER_FIELDS);

        try {
            // Rails: first (non-transactional) attribute-assignment block.
            $billingConfiguration = is_array($args['billing_configuration'] ?? null) ? $args['billing_configuration'] : [];
            $shippingAddress = is_array($args['shipping_address'] ?? null) ? $args['shipping_address'] : [];

            if (array_key_exists('currency', $args)) {
                UpdateCurrencyService::call(
                    customer: $customer,
                    currency: $args['currency'],
                    customerUpdate: true,
                )->raiseIfError();
            }

            $this->assignParams($customer, $args, $shippingAddress, $billingConfiguration);

            if (array_key_exists('finalize_zero_amount_invoice', $args)) {
                $mapped = FinalizeZeroAmountInvoice::fromOption($args['finalize_zero_amount_invoice']);

                if ($mapped === null) {
                    $result->validationFailure(['finalize_zero_amount_invoice' => ['value_is_invalid']])->raiseIfError();
                }

                $customer->finalize_zero_amount_invoice = $mapped;
            }

            $this->assignPremiumAttributes($customer, $args);

            if (array_key_exists('payment_provider', $args)) {
                $customer->payment_provider = $args['payment_provider'];
            }
            if (array_key_exists('payment_provider_code', $args)) {
                $customer->payment_provider_code = $args['payment_provider_code'];
            }
            if (array_key_exists('invoice_footer', $args)) {
                $customer->invoice_footer = $args['invoice_footer'];
            }

            if (array_key_exists('billing_configuration', $args)) {
                if (array_key_exists('invoice_footer', $billingConfiguration)) {
                    $customer->invoice_footer = $billingConfiguration['invoice_footer'];
                }
            }

            UpdateInvoiceIssuingDateSettingsService::call(customer: $customer, params: $args)->raiseIfError();

            if (array_key_exists('net_payment_term', $args)) {
                // TODO(port): Customers::UpdateInvoicePaymentDueDateService —
                // updates draft invoices' payment due dates.
            }

            // Rails: external_id + account_type are not editable once the
            // customer is attached to subscriptions.
            $billingEntityChanged = false;

            if (array_key_exists('billing_entity_code', $args)) {
                $billingEntity = $customer->organization->billingEntities()
                    ->where('code', $args['billing_entity_code'])
                    ->firstOrFail();

                $customer->billing_entity_id = $billingEntity->id;
                $billingEntityChanged = $customer->isDirty('billing_entity_id');
            }

            if ($customer->editable()) {
                if (array_key_exists('external_id', $args)) {
                    $customer->external_id = $args['external_id'];
                }

                if ($this->revenueShareEnabled($customer->organization) && array_key_exists('account_type', $args)) {
                    $customer->account_type = $args['account_type'];
                }
            }

            // TODO(port): auto-dunning applied_dunning_campaign handling
            // (premium auto_dunning integration).
            // Partner accounts are excluded from dunning campaigns:
            if ($customer->partnerAccount()) {
                $customer->exclude_from_dunning_campaign = true;
            }

            DB::transaction(function () use (
                $customer,
                $args,
                $originalTaxValues,
                $billingEntityChanged,
                $result,
            ): void {
                // TODO(port): payment provider removal (discard provider
                // customer + payment methods).

                // TODO(port): ManageInvoiceCustomSectionsService.

                $errors = $customer->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $customer->save();

                // TODO(port): error_details tax_error cleanup when the
                // address changed; RefreshInvoicesSearchTermsJob when the
                // searchable fields changed.

                $taxAttributesChanged = false;

                foreach ($originalTaxValues as $key => $value) {
                    if (array_key_exists($key, $args) && $args[$key] !== $value) {
                        $taxAttributesChanged = true;
                    }
                }

                $euTaxCodeResult = EuAutoTaxesService::call(
                    customer: $customer,
                    newRecord: false,
                    taxAttributesChanged: $taxAttributesChanged || $billingEntityChanged,
                );

                $taxCodes = $args['tax_codes'] ?? null;

                if ($euTaxCodeResult->success()) {
                    $taxCodes = array_values(array_unique([...($taxCodes ?? []), $euTaxCodeResult->tax_code]));
                }

                // Rails: EU-managed taxes belong to the previous billing
                // entity — carry over the non-EU ones when it changed.
                if ($billingEntityChanged && $taxCodes === null) {
                    $taxCodes = $customer->taxes()
                        ->where('code', 'not ilike', 'lago_eu%')
                        ->pluck('code')
                        ->all();
                }

                if (is_array($taxCodes)) {
                    ApplyTaxesService::call(customer: $customer, taxCodes: $taxCodes)->raiseIfError();
                }

                if (array_key_exists('metadata', $args) && is_array($args['metadata'])) {
                    Metadata\UpdateService::call(customer: $customer, params: $args['metadata'])->raiseIfError();
                }
            });

            // TODO(port): payment provider customers batch / legacy provider
            // customer handling.
            // TODO(port): IntegrationCustomers::CreateOrUpdateBatchService.
            // Rails: SendWebhookJob.perform_later("customer.updated", customer)
            // — right after the transaction block.
            SendWebhookJob::performLater('customer.updated', $customer);

            $result->customer = $customer;

            return $result;
        } catch (Throwable $e) {
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $result->notFoundFailure('billing_entity');
            }

            if ($e instanceof \App\Services\Failures\FailedResult) {
                // Rails: `rescue BaseService::FailedResult -> e.result` — the
                // nested failure's own result is returned untouched.
                /** @var \App\Services\Failures\FailedResult $e */
                return $e->result;
            }

            throw $e;
        }
    }

    protected function assignParams(Customer $customer, array $args, array $shippingAddress, array $billingConfiguration): void
    {
        if (array_key_exists('name', $args)) {
            $customer->name = $args['name'];
        }
        if (array_key_exists('tax_identification_number', $args)) {
            $customer->tax_identification_number = $args['tax_identification_number'];
        }
        if (array_key_exists('country', $args)) {
            $customer->country = $args['country'] !== null ? mb_strtoupper($args['country']) : null;
        }
        if (array_key_exists('address_line1', $args)) {
            $customer->address_line1 = $args['address_line1'];
        }
        if (array_key_exists('address_line2', $args)) {
            $customer->address_line2 = $args['address_line2'];
        }
        if (array_key_exists('state', $args)) {
            $customer->state = $args['state'];
        }
        if (array_key_exists('zipcode', $args)) {
            $customer->zipcode = $args['zipcode'];
        }
        if (array_key_exists('email', $args)) {
            $customer->email = $args['email'];
        }
        if (array_key_exists('city', $args)) {
            $customer->city = $args['city'];
        }
        if (array_key_exists('url', $args)) {
            $customer->url = $args['url'];
        }
        if (array_key_exists('phone', $args)) {
            $customer->phone = $args['phone'];
        }
        if (array_key_exists('logo_url', $args)) {
            $customer->logo_url = $args['logo_url'];
        }
        if (array_key_exists('legal_name', $args)) {
            $customer->legal_name = $args['legal_name'];
        }
        if (array_key_exists('legal_number', $args)) {
            $customer->legal_number = $args['legal_number'];
        }
        if (array_key_exists('external_salesforce_id', $args)) {
            $customer->external_salesforce_id = $args['external_salesforce_id'];
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
        if (array_key_exists('firstname', $args)) {
            $customer->firstname = $args['firstname'];
        }
        if (array_key_exists('lastname', $args)) {
            $customer->lastname = $args['lastname'];
        }
        if (array_key_exists('customer_type', $args)) {
            $customer->customer_type = $args['customer_type'];
        }

        if (array_key_exists('document_locale', $billingConfiguration)) {
            $customer->document_locale = $billingConfiguration['document_locale'];
        }
    }

    protected function validMetadataCount($metadata): bool
    {
        if ($metadata === null || $metadata === []) {
            return true;
        }

        return count($metadata) <= \App\Models\CustomerMetadata::COUNT_PER_CUSTOMER;
    }

    protected function revenueShareEnabled(object $organization): bool
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
    }

    /**
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
