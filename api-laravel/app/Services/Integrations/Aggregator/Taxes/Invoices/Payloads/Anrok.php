<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Invoices\Payloads;

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Integration;
use Illuminate\Support\Carbon;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\Taxes\Invoices\ChargeFeeGroup;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Invoices::Payloads::Anrok
 * (…/taxes/invoices/payloads/anrok.rb).
 */
final class Anrok extends BasePayload
{
    /**
     * @param  list<Fee|ChargeFeeGroup>  $fees
     */
    public function __construct(
        Integration $integration,
        public readonly Customer $customer,
        public readonly Invoice $invoice,
        public readonly ?IntegrationCustomer $integration_customer,
        public readonly array $fees = [],
    ) {
        parent::__construct($integration, $customer->billingEntity);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function body(): array
    {
        $shipping = $this->customer->effectiveShippingAddress();

        return [
            [
                'issuing_date' => $this->issuing_date(),
                'currency' => $this->invoice->currency,
                'contact' => [
                    'external_id' => $this->integration_customer?->external_customer_id
                        ?? $this->customer->external_id,
                    'name' => $this->customer->name,
                    'address_line_1' => $shipping['address_line1'],
                    'city' => $shipping['city'],
                    'zip' => $shipping['zipcode'],
                    'country' => $shipping['country'],
                    'taxable' => $this->customer->taxable(),
                    'tax_number' => $this->customer->tax_identification_number,
                ],
                'fees' => array_map(fn (Fee|ChargeFeeGroup $fee) => $this->fee_item($fee), $this->fees),
                'tax_date' => $this->issuing_date(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fee_item(Fee|ChargeFeeGroup $fee): array
    {
        return [
            'item_key' => $fee->itemKey(),
            'item_id' => isset($fee->id) ? $fee->id : $fee->itemKey(),
            'item_code' => $this->mappedItem($fee)?->external_id ?? null,
            'amount_cents' => (int) $fee->subTotalExcludingTaxesAmountCents(),
        ];
    }

    /**
     * NOTE: Anrok API requires issuing date to be 30 days in the future at
     * most.
     */
    private function issuing_date(): ?string
    {
        $issuingDate = $this->invoice->issuing_date !== null
            ? Carbon::parse($this->invoice->issuing_date)
            : null;
        $in30Days = Carbon::now()->addDays(30)->startOfDay();

        if ($issuingDate === null) {
            return $in30Days->toDateString();
        }

        return $issuingDate->lt($in30Days) ? $issuingDate->toDateString() : $in30Days->toDateString();
    }
}
