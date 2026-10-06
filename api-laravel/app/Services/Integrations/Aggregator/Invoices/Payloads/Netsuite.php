<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Invoices\Payloads;

use App\Models\Fee;
use App\Enums\FeeType;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::Payloads::Netsuite
 * (…/aggregator/invoices/payloads/netsuite.rb) — the restlet-script invoice
 * shape: columns, item lines and the Ava-ra tax details.
 *
 * TODO(port): the fullInvoicePayload embed (Rails serializes the invoice
 * through V1::InvoiceSerializer) — null until the serializer slice lands.
 */
final class Netsuite extends BasePayload
{
    public const int MAX_DECIMALS = 15;

    public const int NS_QUANTITY_LIMIT = 10_000_000_000;

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $result = [
            'type' => 'invoice',
            'isDynamic' => true,
            'columns' => $this->columns(),
            'lines' => [
                [
                    'sublistId' => 'item',
                    'lineItems' => $this->fee_items() + $this->discounts(),
                ],
            ],
            'options' => [
                'ignoreMandatoryFields' => false,
                'fullInvoicePayload' => null, // TODO(port): V1::InvoiceSerializer.
            ],
        ];

        if ($this->tax_item_complete()) {
            $result['taxdetails'] = [
                [
                    'sublistId' => 'taxdetails',
                    'lineItems' => $this->tax_line_items() + $this->discount_taxes(),
                ],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function item(Fee $fee): array
    {
        $mappedItem = $this->mapped_invoice_item($fee);

        if ($mappedItem === null) {
            throw new \App\Services\Integrations\Aggregator\BasePayload\Failure('invalid_mapping');
        }

        $isCharge = $fee->typeEnum() === FeeType::Charge;

        $fromProperty = $isCharge ? 'charges_from_datetime' : 'from_datetime';
        $toProperty = $isCharge ? 'charges_to_datetime' : 'to_datetime';

        $quantityValue = $this->limited_rate($fee->units);
        $unitRateValue = $this->limited_rate($fee->precise_unit_amount);
        $lineAmountValue = $this->limited_rate($this->amount($fee->amount_cents, resource: $this->invoice));

        if (is_numeric($quantityValue) && abs((float) $quantityValue) >= self::NS_QUANTITY_LIMIT) {
            $quantityValue = 1;
            $unitRateValue = $lineAmountValue;
        }

        $properties = (array) ($fee->properties ?? []);

        return [
            'item' => $mappedItem->external_id,
            'account' => $mappedItem->external_account_code,
            'quantity' => $quantityValue,
            'rate' => $unitRateValue,
            'amount' => $lineAmountValue,
            'taxdetailsreference' => $fee->id,
            'custcol_service_period_date_from' => $this->service_period_date($properties[$fromProperty] ?? null),
            'custcol_service_period_date_to' => $this->service_period_date($properties[$toProperty] ?? null),
            'description' => $this->fee_item_name($fee),
            'item_source' => $this->fee_item_source($fee),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function discounts(): array
    {
        $output = [];

        if ($this->coupon_item() !== null && $this->invoice->coupons_amount_cents > 0) {
            $output[] = [
                'item' => $this->coupon_item()?->external_id,
                'account' => $this->coupon_item()?->external_account_code,
                'quantity' => 1,
                'rate' => -$this->amount($this->invoice->coupons_amount_cents, resource: $this->invoice),
                'taxdetailsreference' => 'coupon_item',
                'description' => implode(',', $this->credit_item_names('coupon')),
                'item_source' => 'coupons',
            ];
        }

        if ($this->credit_item() !== null && $this->invoice->prepaid_credit_amount_cents > 0) {
            $output[] = [
                'item' => $this->credit_item()?->external_id,
                'account' => $this->credit_item()?->external_account_code,
                'quantity' => 1,
                'rate' => -$this->amount($this->invoice->prepaid_credit_amount_cents, resource: $this->invoice),
                'taxdetailsreference' => 'credit_item',
                'description' => 'Prepaid credits',
                'item_source' => 'prepaid_credits',
            ];
        }

        if ($this->credit_item() !== null && $this->invoice->progressive_billing_credit_amount_cents > 0) {
            $output[] = [
                'item' => $this->credit_item()?->external_id,
                'account' => $this->credit_item()?->external_account_code,
                'quantity' => 1,
                'rate' => -$this->amount($this->invoice->progressive_billing_credit_amount_cents, resource: $this->invoice),
                'taxdetailsreference' => 'credit_item_progressive_billing',
                'description' => implode(',', $this->credit_item_names('progressive_billing')),
                'item_source' => 'progressive_billing_credits',
            ];
        }

        if ($this->credit_note_item() !== null && $this->invoice->credit_notes_amount_cents > 0) {
            $output[] = [
                'item' => $this->credit_note_item()?->external_id,
                'account' => $this->credit_note_item()?->external_account_code,
                'quantity' => 1,
                'rate' => -$this->amount($this->invoice->credit_notes_amount_cents, resource: $this->invoice),
                'taxdetailsreference' => 'credit_note_item',
                'description' => implode(',', $this->credit_item_names('credit_note')),
                'item_source' => 'credit_note_credits',
            ];
        }

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(): array
    {
        $result = [
            'tranid' => $this->invoice->number,
            'custbody_ava_disable_tax_calculation' => true,
            'custbody_lago_invoice_link' => $this->invoice_url(),
            'trandate' => $this->issuing_date(),
            'duedate' => $this->due_date(),
            'taxdetailsoverride' => true,
            'custbody_lago_id' => $this->invoice->id,
            'entity' => $this->integration_customer->external_customer_id,
            'lago_plan_codes' => implode(',', $this->invoice->invoiceSubscriptions
                ->map(fn ($invoiceSubscription) => $invoiceSubscription->subscription?->plan?->code)
                ->filter(fn ($code) => $code !== null && $code !== '')
                ->all()),
        ];

        $mappedCurrency = $this->netsuite_currency_for(currency: $this->invoice->currency);

        if ($mappedCurrency !== null && $mappedCurrency !== '') {
            $result['currency'] = (string) $mappedCurrency;
        }

        if ($this->tax_item()?->tax_nexus !== null) {
            $result['nexus'] = $this->tax_item()->tax_nexus;
        }

        $result['taxregoverride'] = true;

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tax_line_items(): array
    {
        return $this->fees()->map(fn (Fee $fee) => $this->tax_line_item($fee))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function tax_line_item(Fee $fee): array
    {
        return [
            'taxdetailsreference' => $fee->id,
            'taxamount' => $this->amount($fee->taxes_amount_cents, resource: $this->invoice),
            'taxbasis' => 1,
            'taxrate' => $fee->taxes_rate,
            'taxtype' => $this->tax_item()?->tax_type,
            'taxcode' => $this->tax_item()?->tax_code,
        ];
    }

    /** Rails: `due_date` — "%-m/%-d/%Y". */
    private function due_date(): ?string
    {
        return $this->invoice->payment_due_date !== null
            ? \Illuminate\Support\Carbon::parse($this->invoice->payment_due_date)->format('n/j/Y')
            : null;
    }

    /** Rails: `issuing_date` — "%-m/%-d/%Y". */
    private function issuing_date(): ?string
    {
        return $this->invoice->issuing_date !== null
            ? \Illuminate\Support\Carbon::parse($this->invoice->issuing_date)->format('n/j/Y')
            : null;
    }

    /**
     * Rails: `netsuite_currency_for` — the currencies collection mapping.
     */
    private function netsuite_currency_for(string $currency): mixed
    {
        $mapping = \App\Models\IntegrationCollectionMappings\NetsuiteCollectionMapping::query()
            ->where('integration_id', $this->integration_customer->integration_id)
            ->where('mapping_type', \App\Models\IntegrationCollectionMappings\BaseCollectionMapping::mappingTypes()['currencies'])
            ->first();

        return $mapping?->currencies[$currency] ?? null;
    }

    /**
     * Rails: `discount_taxes` — the tax details lines of the discount lines
     * (only reached with a complete tax mapping — TODO(port)).
     *
     * @return list<array<string, mixed>>
     */
    private function discount_taxes(): array
    {
        $output = [];

        if ($this->invoice->coupons_amount_cents > 0) {
            $taxDiffAmountCents = $this->invoice->taxes_amount_cents - $this->fees()
                ->sum(fn (Fee $fee) => (int) ($fee->taxes_amount_cents ?? 0));

            $output[] = [
                'taxbasis' => 1,
                'taxamount' => $this->amount($taxDiffAmountCents, resource: $this->invoice),
                'taxrate' => $this->invoice->taxes_rate,
                'taxtype' => $this->tax_item()?->tax_type,
                'taxcode' => $this->tax_item()?->tax_code,
                'taxdetailsreference' => 'coupon_item',
            ];
        }

        foreach (['credit_item', 'credit_item_progressive_billing', 'credit_note_item'] as $reference) {
            $hasAmount = match ($reference) {
                'credit_item' => $this->invoice->prepaid_credit_amount_cents > 0 && $this->credit_item() !== null,
                'credit_item_progressive_billing' => $this->invoice->progressive_billing_credit_amount_cents > 0 && $this->credit_item() !== null,
                'credit_note_item' => $this->invoice->credit_notes_amount_cents > 0 && $this->credit_note_item() !== null,
            };

            if (! $hasAmount) {
                continue;
            }

            $output[] = [
                'taxbasis' => 1,
                'taxamount' => 0,
                'taxrate' => $this->invoice->taxes_rate,
                'taxtype' => $this->tax_item()?->tax_type,
                'taxcode' => $this->tax_item()?->tax_code,
                'taxdetailsreference' => $reference,
            ];
        }

        return $output;
    }

    /**
     * Rails: `limited_rate` — trims the decimal part so the string form
     * stays within MAX_DECIMALS characters.
     */
    private function limited_rate(mixed $preciseUnitAmount): mixed
    {
        $unitAmountStr = (string) $preciseUnitAmount;

        if (mb_strlen($unitAmountStr) <= self::MAX_DECIMALS) {
            return $preciseUnitAmount;
        }

        $decimalPosition = mb_strpos($unitAmountStr, '.');

        if ($decimalPosition === false) {
            return $preciseUnitAmount;
        }

        $scale = self::MAX_DECIMALS - 1 - $decimalPosition;

        return round((float) $preciseUnitAmount, max(0, $scale));
    }

    /** Rails: `fee.item_name` (the parts the payload consumes). */
    private function fee_item_name(Fee $fee): ?string
    {
        $type = $fee->typeEnum();

        if ($type === FeeType::Charge) {
            return $fee->charge?->billableMetric?->name;
        }

        if ($type === FeeType::AddOn) {
            return $fee->addOn?->name;
        }

        if ($type === FeeType::FixedCharge) {
            return $fee->fixedCharge?->addOn?->name;
        }

        return null; // TODO(port): credit / product fee item names.
    }

    /** Rails: `fee.item_source` (the parts the payload consumes). */
    private function fee_item_source(Fee $fee): ?string
    {
        $type = $fee->typeEnum();

        if ($type === FeeType::AddOn) {
            return $fee->addOn?->code;
        }

        if ($type === FeeType::FixedCharge) {
            return $fee->fixedCharge?->addOn?->code;
        }

        return null; // TODO(port): credit / product fee item sources.
    }

    /**
     * Rails: `invoice.credits.{coupon,progressive_billing_invoice,credit_note}_kind
     * .map(&:item_name)` — the discount-line descriptions.
     *
     * @return list<string>
     */
    private function credit_item_names(string $kind): array
    {
        $query = $this->invoice->credits()->getQuery();

        $credits = match ($kind) {
            'coupon' => $query->whereNotNull('applied_coupon_id')->get(),
            'progressive_billing' => $query->whereNotNull('progressive_billing_invoice_id')->get(),
            'credit_note' => $query->whereNotNull('credit_note_id')->get(),
        };

        return $credits->map(function ($credit) {
            if ($credit->applied_coupon_id !== null) {
                return (string) ($credit->appliedCoupon?->coupon?->name ?? '');
            }

            if ($credit->progressive_billing_invoice_id !== null) {
                return (string) ($credit->progressiveBillingInvoice?->number ?? '');
            }

            return (string) ($credit->creditNote?->invoice?->number ?? ''); // TODO(port): CreditNote relation.
        })->all();
    }

    /** Rails: `fee.properties[from_property]&.to_date&.strftime("%-m/%-d/%Y")`. */
    private function service_period_date(mixed $date): ?string
    {
        return $date !== null
            ? \Illuminate\Support\Carbon::parse($date)->format('n/j/Y')
            : null;
    }
}
