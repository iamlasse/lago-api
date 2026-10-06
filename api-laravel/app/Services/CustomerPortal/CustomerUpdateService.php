<?php

declare(strict_types=1);

namespace App\Services\CustomerPortal;

use Throwable;
use App\Models\Customer;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Customers\EuAutoTaxesService;
use App\Services\Customers\ApplyTaxesService;

use function array_key_exists;

/**
 * Port of Rails' CustomerPortal::CustomerUpdateService
 * (app/services/customer_portal/customer_update_service.rb) — the
 * self-service profile update behind the updateCustomerPortalCustomer
 * mutation. Only the whitelisted portal attributes are writable (the SDL's
 * UpdateCustomerPortalCustomerInput is already narrower, but the service
 * keeps Rails' key-by-key gate); country inputs are uppercased.
 *
 * TODO(port): RefreshInvoicesSearchTermsJob (perform_after_commit) when the
 * searchable fields changed — the job is not ported yet.
 */
class CustomerUpdateService extends BaseService
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

        if ($customer === null) {
            return $result->notFoundFailure('customer');
        }

        $args = $this->args;

        try {
            $originalTaxValues = [
                'tax_identification_number' => $customer->tax_identification_number,
                'zipcode' => $customer->zipcode,
                'country' => $customer->country,
            ];

            $originalSearchableValues = $this->slice($customer, Customer::SEARCHABLE_CUSTOMER_FIELDS);

            DB::transaction(function () use ($customer, $args, $originalTaxValues, $originalSearchableValues, $result): void {
                if (array_key_exists('customer_type', $args)) {
                    $customer->customer_type = $args['customer_type'];
                }
                if (array_key_exists('name', $args)) {
                    $customer->name = $args['name'];
                }
                if (array_key_exists('firstname', $args)) {
                    $customer->firstname = $args['firstname'];
                }
                if (array_key_exists('lastname', $args)) {
                    $customer->lastname = $args['lastname'];
                }
                if (array_key_exists('legal_name', $args)) {
                    $customer->legal_name = $args['legal_name'];
                }
                if (array_key_exists('tax_identification_number', $args)) {
                    $customer->tax_identification_number = $args['tax_identification_number'];
                }
                if (array_key_exists('email', $args)) {
                    $customer->email = $args['email'];
                }

                if (array_key_exists('document_locale', $args)) {
                    $customer->document_locale = $args['document_locale'];
                }

                if (array_key_exists('address_line1', $args)) {
                    $customer->address_line1 = $args['address_line1'];
                }
                if (array_key_exists('address_line2', $args)) {
                    $customer->address_line2 = $args['address_line2'];
                }
                if (array_key_exists('zipcode', $args)) {
                    $customer->zipcode = $args['zipcode'];
                }
                if (array_key_exists('city', $args)) {
                    $customer->city = $args['city'];
                }
                if (array_key_exists('state', $args)) {
                    $customer->state = $args['state'];
                }
                if (array_key_exists('country', $args)) {
                    $customer->country = is_string($args['country']) ? mb_strtoupper($args['country']) : $args['country'];
                }

                $shippingAddress = is_array($args['shipping_address'] ?? null) ? $args['shipping_address'] : [];

                if (array_key_exists('address_line1', $shippingAddress)) {
                    $customer->shipping_address_line1 = $shippingAddress['address_line1'];
                }
                if (array_key_exists('address_line2', $shippingAddress)) {
                    $customer->shipping_address_line2 = $shippingAddress['address_line2'];
                }
                if (array_key_exists('zipcode', $shippingAddress)) {
                    $customer->shipping_zipcode = $shippingAddress['zipcode'];
                }
                if (array_key_exists('city', $shippingAddress)) {
                    $customer->shipping_city = $shippingAddress['city'];
                }
                if (array_key_exists('state', $shippingAddress)) {
                    $customer->shipping_state = $shippingAddress['state'];
                }
                if (array_key_exists('country', $shippingAddress)) {
                    $customer->shipping_country = is_string($shippingAddress['country'])
                        ? mb_strtoupper($shippingAddress['country'])
                        : $shippingAddress['country'];
                }

                // Rails: customer.save! — the model validations surface as the
                // record_validation_failure (ActiveRecord::RecordInvalid).
                $errors = $customer->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $customer->save();
                $customer->refresh();

                // Rails: RefreshInvoicesSearchTermsJob.perform_after_commit
                // when the searchable fields changed. TODO(port).
                if ($this->slice($customer, Customer::SEARCHABLE_CUSTOMER_FIELDS) !== $originalSearchableValues) {
                    // TODO(port): Customers::RefreshInvoicesSearchTermsJob.
                }

                $taxCodes = [];

                $taxAttributesChanged = false;

                foreach ($originalTaxValues as $key => $value) {
                    if (array_key_exists($key, $args) && $args[$key] !== $value) {
                        $taxAttributesChanged = true;
                    }
                }

                $euTaxCodeResult = EuAutoTaxesService::call(
                    customer: $customer,
                    newRecord: false,
                    taxAttributesChanged: $taxAttributesChanged,
                );

                if ($euTaxCodeResult->success()) {
                    $taxCodes[] = $euTaxCodeResult->tax_code;
                }

                if ($taxCodes !== []) {
                    ApplyTaxesService::call(customer: $customer, taxCodes: $taxCodes)->raiseIfError();
                }
            });

            $result->customer = $customer;

            return $result;
        } catch (Throwable $e) {
            if ($e instanceof \App\Services\Failures\FailedResult) {
                // Rails: `rescue BaseService::FailedResult -> e.result`.
                /** @var \App\Services\Failures\FailedResult $e */
                return $e->result;
            }

            throw $e;
        }
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function slice(Customer $customer, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = $customer->getAttribute($field);
        }

        return $values;
    }
}
