<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Contacts\Payloads;

/**
 * Port of Rails' Integrations::Aggregator::Contacts::Payloads::Avalara —
 * the Avalara company id, the shipping address and the tax number.
 */
final class Avalara extends BasePayload
{
    /**
     * @return list<array<string, mixed>>
     */
    public function create_body(): array
    {
        return [$this->contact_body()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function update_body(): array
    {
        return [$this->contact_body()];
    }

    /**
     * @return array<string, mixed>
     */
    private function contact_body(): array
    {
        $shipping = $this->customer->effectiveShippingAddress();

        return [
            'company_id' => $this->integration->getFromSettings('company_id') !== null
                ? (int) $this->integration->getFromSettings('company_id')
                : null,
            'external_id' => $this->customer->id,
            'name' => $this->name(),
            'address_line_1' => $shipping['address_line1'],
            'city' => $shipping['city'],
            'zip' => $shipping['zipcode'],
            'country' => $shipping['country'],
            'state' => $shipping['state'],
            'tax_number' => $this->customer->tax_identification_number,
        ];
    }

    private function name(): ?string
    {
        if ($this->customer->name !== null && $this->customer->name !== '') {
            return $this->customer->name;
        }

        return mb_trim((string) $this->customer->firstname.' '.(string) $this->customer->lastname);
    }
}
