<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::OrganizationSerializer (app/serializers/v1/
 * organization_serializer.rb).
 */
class OrganizationSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        $payload = [
            'lago_id' => $this->model->id,
            'name' => $this->model->name,
            'slug' => $this->model->slug,
            'default_currency' => $this->model->default_currency,
            'created_at' => $this->serializeDatetime($this->model->created_at),
            'webhook_url' => $this->webhookUrls()[0] ?? '',
            'webhook_urls' => $this->webhookUrls(),
            'country' => $this->model->country,
            'address_line1' => $this->model->address_line1,
            'address_line2' => $this->model->address_line2,
            'state' => $this->model->state,
            'zipcode' => $this->model->zipcode,
            'email' => $this->model->email,
            'city' => $this->model->city,
            'legal_name' => $this->model->legal_name,
            'legal_number' => $this->model->legal_number,
            'timezone' => $this->model->timezone,
            'net_payment_term' => $this->model->net_payment_term,
            'email_settings' => $this->model->email_settings,
            'document_numbering' => $this->model->document_numbering?->label(),
            'document_number_prefix' => $this->model->document_number_prefix,
            'tax_identification_number' => $this->model->tax_identification_number,
            'finalize_zero_amount_invoice' => $this->model->finalize_zero_amount_invoice,
            'billing_configuration' => $this->billingConfiguration(),
            'events_store' => $this->model->eventsStore(),
        ];

        if ($this->include('taxes')) {
            $payload = [...$payload, ...$this->taxes()];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    protected function billingConfiguration(): array
    {
        return [
            'invoice_footer' => $this->model->invoice_footer,
            'invoice_grace_period' => $this->model->invoice_grace_period,
            'document_locale' => $this->model->document_locale,
        ];
    }

    /** @return array<string, mixed> */
    protected function taxes(): array
    {
        $taxes = $this->model->taxes()->where('applied_to_organization', true)->get();

        return (new CollectionSerializer($taxes, TaxSerializer::class, [
            'collection_name' => 'taxes',
        ]))->serialize();
    }

    /** @return list<string> */
    protected function webhookUrls(): array
    {
        return $this->model->webhookEndpoints->pluck('webhook_url')->all();
    }
}
