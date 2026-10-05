<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts\Payloads;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::Payloads::Anrok — the
 * display name replaces the plain name column.
 */
final class Anrok extends BasePayload
{
    /**
     * @return list<array<string, mixed>>
     */
    public function create_body(): array
    {
        return [
            [
                'name' => $this->display_name(),
                'city' => $this->customer->city,
                'zip' => $this->customer->zipcode,
                'country' => $this->customer->country,
                'state' => $this->customer->state,
                'email' => $this->email(),
                'phone' => $this->phone(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function update_body(): array
    {
        return [
            [
                'id' => $this->integration_customer?->external_customer_id,
                'name' => $this->display_name(),
                'city' => $this->customer->city,
                'zip' => $this->customer->zipcode,
                'country' => $this->customer->country,
                'state' => $this->customer->state,
                'email' => $this->email(),
                'phone' => $this->phone(),
            ],
        ];
    }
}
