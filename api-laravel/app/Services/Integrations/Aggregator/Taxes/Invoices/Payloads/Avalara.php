<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Invoices\Payloads;

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Support\Currency;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\Taxes\Invoices\ChargeFeeGroup;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Invoices::Payloads::Avalara
 * (…/taxes/invoices/payloads/avalara.rb).
 */
final class Avalara extends BasePayload
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
        $billingEntity = $this->billingEntity;

        return [
            [
                'issuing_date' => $this->invoice->issuing_date,
                'currency' => $this->invoice->currency,
                'contact' => [
                    'external_id' => $this->integration_customer?->external_customer_id,
                    'name' => $this->customer->name,
                    'address_line_1' => $shipping['address_line1'],
                    'city' => $shipping['city'],
                    'zip' => $shipping['zipcode'],
                    'region' => $shipping['state'],
                    'country' => $shipping['country'],
                    'taxable' => $this->customer->taxable(),
                    'tax_number' => $this->customer->tax_identification_number,
                ],
                'billing_entity' => [
                    'address_line_1' => $billingEntity?->address_line1,
                    'city' => $billingEntity?->city,
                    'zip' => $billingEntity?->zipcode,
                    'region' => $billingEntity?->state,
                    'country' => $billingEntity?->country,
                ],
                'fees' => array_map(fn (Fee|ChargeFeeGroup $fee) => $this->fee_item($fee), $this->fees),
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
            'unit' => $fee instanceof ChargeFeeGroup ? $fee->units() : (string) $fee->units,
            'amount' => $this->item_amount($fee),
        ];
    }

    private function item_amount(Fee|ChargeFeeGroup $fee): string
    {
        $amount = (int) $fee->subTotalExcludingTaxesAmountCents()
            / Currency::subunitToUnit((string) $this->subunit_currency($fee));

        if ($this->invoice->isVoided()) {
            $amount *= -1;
        }

        // Rails: `amount.to_s` on a Float — whole numbers keep the ".0".
        if (abs($amount - round($amount)) < 1e-9) {
            return sprintf('%.1F', $amount);
        }

        return (string) $amount;
    }

    /**
     * Rails: `subunit_to_unit(fee)` — the fee currency's subunit; grouped
     * lines have no currency of their own, so the invoice's is used.
     */
    private function subunit_currency(Fee|ChargeFeeGroup $fee): string
    {
        if ($fee instanceof Fee) {
            return (string) $fee->amount_currency;
        }

        return (string) $this->invoice->currency;
    }
}
