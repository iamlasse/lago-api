<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Companies\Payloads;

use App\Services\Integrations\Aggregator\Contacts\Payloads\BasePayload as ContactsBasePayload;

/**
 * Port of Rails' Integrations::Aggregator::Companies::Payloads::Hubspot —
 * the company properties shape (the lago_* custom properties the
 * deploy-properties leg installs on the portal).
 */
final class Hubspot extends ContactsBasePayload
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
                'lago_tax_identification_number' => $this->customer->tax_identification_number,
                'lago_customer_link' => $this->customer_url(),
            ], array_filter([
                'name' => $this->customer->name,
                'domain' => $this->clean_url($this->customer->url),
            ], fn ($value) => $value !== null && $value !== '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function update_body(): array
    {
        return [
            'companyId' => $this->integration_customer?->external_customer_id,
            'input' => [
                'properties' => array_merge([
                    'lago_customer_id' => $this->customer->id,
                    'lago_customer_external_id' => $this->customer->external_id,
                    'lago_billing_email' => $this->customer->email,
                    'lago_tax_identification_number' => $this->customer->tax_identification_number,
                    'lago_customer_link' => $this->customer_url(),
                ], array_filter([
                    'name' => $this->customer->name,
                    'domain' => $this->clean_url($this->customer->url),
                ], fn ($value) => $value !== null && $value !== '')),
            ],
        ];
    }
}
