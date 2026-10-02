<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;
use App\Serializers\V1\Customers\MetadataSerializer;

/**
 * Port of Rails' V1::CustomerSerializer (app/serializers/v1/
 * customer_serializer.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): payment-provider billing configuration extras
 *   (provider_customer_id / provider_payment_methods / settings) once the
 *   PaymentProviderCustomers models exist.
 * - TODO(port): integration_customers, applicable_invoice_custom_sections
 *   and error_details includes are emitted as empty collections.
 */
class CustomerSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        $payload = [
            'lago_id' => $this->model->id,
            'billing_entity_code' => $this->model->billingEntity?->code,
            'external_id' => $this->model->external_id,
            'account_type' => $this->model->getRawOriginal('account_type'),
            'name' => $this->model->name,
            'firstname' => $this->model->firstname,
            'lastname' => $this->model->lastname,
            'customer_type' => $this->model->getRawOriginal('customer_type'),
            'sequential_id' => $this->model->sequential_id,
            'slug' => $this->model->slug,
            'created_at' => $this->serializeDatetime($this->model->created_at),
            'updated_at' => $this->serializeDatetime($this->model->updated_at),
            'country' => $this->model->country,
            'address_line1' => $this->model->address_line1,
            'address_line2' => $this->model->address_line2,
            'state' => $this->model->state,
            'zipcode' => $this->model->zipcode,
            'email' => $this->model->email,
            'city' => $this->model->city,
            'url' => $this->model->url,
            'phone' => $this->model->phone,
            'logo_url' => $this->model->logo_url,
            'legal_name' => $this->model->legal_name,
            'legal_number' => $this->model->legal_number,
            'currency' => $this->model->currency,
            'tax_identification_number' => $this->model->tax_identification_number,
            'timezone' => $this->model->timezone,
            'applicable_timezone' => $this->model->applicableTimezone(),
            'net_payment_term' => $this->model->net_payment_term,
            'external_salesforce_id' => $this->model->external_salesforce_id,
            'finalize_zero_amount_invoice' => $this->model->finalize_zero_amount_invoice?->label(),
            'billing_configuration' => $this->billingConfiguration(),
            'shipping_address' => $this->model->shippingAddress(),
            'skip_invoice_custom_sections' => $this->model->skip_invoice_custom_sections,
        ];

        $payload = [...$payload, ...$this->metadata()];

        if ($this->include('taxes')) {
            $payload = [...$payload, ...$this->taxes()];
        }

        if ($this->include('vies_check')) {
            $payload = [...$payload, ...$this->viesCheck()];
        }

        if ($this->include('integration_customers')) {
            // TODO(port): IntegrationCustomers models — empty collection.
            $payload['integration_customers'] = [];
        }

        if ($this->include('applicable_invoice_custom_sections')) {
            // TODO(port): InvoiceCustomSection models — empty collection.
            $payload['applicable_invoice_custom_sections'] = [];
        }

        if ($this->include('error_details')) {
            // TODO(port): ErrorDetail models — empty collection.
            $payload['error_details'] = [];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    protected function metadata(): array
    {
        return (new CollectionSerializer(
            $this->model->metadata,
            MetadataSerializer::class,
            ['collection_name' => 'metadata'],
        ))->serialize();
    }

    /** @return array<string, mixed> */
    protected function taxes(): array
    {
        return (new CollectionSerializer(
            $this->model->taxes,
            TaxSerializer::class,
            ['collection_name' => 'taxes'],
        ))->serialize();
    }

    /** @return array<string, mixed> */
    protected function billingConfiguration(): array
    {
        return [
            'invoice_grace_period' => $this->model->invoice_grace_period,
            'payment_provider' => $this->model->payment_provider,
            'payment_provider_code' => $this->model->payment_provider_code,
            'document_locale' => $this->model->document_locale,
            'subscription_invoice_issuing_date_anchor' => $this->model->getRawOriginal('subscription_invoice_issuing_date_anchor'),
            'subscription_invoice_issuing_date_adjustment' => $this->model->getRawOriginal('subscription_invoice_issuing_date_adjustment'),
        ];
    }

    /** @return array<string, mixed> */
    protected function viesCheck(): array
    {
        return [
            'vies_check' => $this->options['vies_check'] ?? null,
        ];
    }
}
