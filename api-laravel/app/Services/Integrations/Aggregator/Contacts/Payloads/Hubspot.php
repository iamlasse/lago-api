<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts\Payloads;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::Payloads::Hubspot —
 * the contact properties shape (the lago_* custom properties).
 */
final class Hubspot extends BasePayload
{
    /**
     * @return array<string, mixed>
     */
    public function create_body(): array
    {
        return [
            'properties' => array_merge([
                'lago_customer_id' => $this->customer->id,
                'lago_customer_external_id' => $this->customer->external_id,
                'lago_billing_email' => $this->customer->email,
                'lago_customer_link' => $this->customer_url(),
            ], array_filter([
                'email' => $this->customer->email,
                'firstname' => $this->customer->firstname,
                'lastname' => $this->customer->lastname,
                'phone' => $this->customer->phone,
                'company' => $this->customer->legal_name,
                'website' => $this->clean_url($this->customer->url),
            ], fn ($value) => $value !== null && $value !== '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function update_body(): array
    {
        return [
            'contactId' => $this->integration_customer?->external_customer_id,
            'input' => [
                'properties' => array_filter([
                    'email' => $this->customer->email,
                    'firstname' => $this->customer->firstname,
                    'lastname' => $this->customer->lastname,
                    'phone' => $this->customer->phone,
                    'company' => $this->customer->legal_name,
                    'website' => $this->clean_url($this->customer->url),
                ], fn ($value) => $value !== null && $value !== ''),
            ],
        ];
    }
}
