<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\CreditNotes\Payloads;

use App\Models\CreditNoteItem;
use App\Serializers\V1\CreditNoteSerializer;

/**
 * Port of Rails' Integrations::Aggregator::CreditNotes::Payloads::Netsuite
 * (…/aggregator/credit_notes/payloads/netsuite.rb) — the restlet-script
 * credit memo shape: columns, item lines, the Ava-ra tax details and the
 * serialized fullCreditNotePayload embed.
 */
final class Netsuite extends BasePayload
{
    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $result = [
            'type' => 'creditmemo',
            'isDynamic' => true,
            'columns' => $this->columns(),
            'lines' => [
                [
                    'sublistId' => 'item',
                    'lineItems' => array_merge($this->netsuite_credit_note_items(), $this->coupons()),
                ],
            ],
            'options' => [
                'ignoreMandatoryFields' => false,
                'fullCreditNotePayload' => [
                    'credit_note_payload' => (new CreditNoteSerializer($this->credit_note, [
                        'root_name' => 'credit_note',
                        'includes' => [
                            'items',
                            'applied_taxes',
                            'error_details',
                            ['customer' => ['integration_customers']],
                        ],
                    ]))->serialize(),
                ],
            ],
        ];

        if ($this->tax_item_complete()) {
            $result['taxdetails'] = [
                [
                    'sublistId' => 'taxdetails',
                    'lineItems' => array_merge($this->tax_line_items_with_adjusted_taxes(), $this->coupon_taxes()),
                ],
            ];
        }

        return $result;
    }

    /**
     * Rails: `coupons` — the coupon adjustment line.
     *
     * @return list<array<string, mixed>>
     */
    protected function coupons(): array
    {
        $output = [];

        if ((int) $this->credit_note->coupons_adjustment_amount_cents > 0) {
            $output[] = [
                'item' => $this->coupon_item()?->external_id,
                'account' => $this->coupon_item()?->external_account_code,
                'quantity' => 1,
                'rate' => (string) -$this->credit_note_amount((int) $this->credit_note->coupons_adjustment_amount_cents),
                'taxdetailsreference' => 'coupon_item',
                'description' => $this->coupon_credits_description(),
            ];
        }

        return $output;
    }

    /**
     * Rails: `credit_note_items` — the item payloads ordered by created_at.
     *
     * @return list<array<string, mixed>>
     */
    private function netsuite_credit_note_items(): array
    {
        return $this->credit_note->items()->orderBy('created_at')->get()
            ->map(fn (CreditNoteItem $item) => $this->netsuite_item($item))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function netsuite_item(CreditNoteItem $credit_note_item): array
    {
        $fee = $credit_note_item->fee;

        $mappedItem = $this->mapped_credit_note_item($fee);

        if ($mappedItem === null) {
            throw new \App\Services\Integrations\Aggregator\BasePayload\Failure('invalid_mapping');
        }

        return [
            'item' => $mappedItem->external_id,
            'account' => $mappedItem->external_account_code,
            'quantity' => 1,
            'rate' => $this->credit_note_amount((int) $credit_note_item->amount_cents),
            'taxdetailsreference' => $credit_note_item->id,
            'description' => $this->netsuite_fee_item_name($fee),
        ];
    }

    /**
     * Rails: `tax_line_items` — one tax line per credit note item.
     *
     * @return list<array<string, mixed>>
     */
    private function tax_line_items(): array
    {
        return $this->credit_note->items()->orderBy('created_at')->get()
            ->map(fn (CreditNoteItem $item) => $this->tax_line_item($item))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function tax_line_item(CreditNoteItem $credit_note_item): array
    {
        return [
            'taxdetailsreference' => $credit_note_item->id,
            'taxamount' => $this->credit_note_amount($this->netsuite_taxes_amount($credit_note_item)),
            'taxbasis' => 1,
            'taxrate' => $credit_note_item->fee->taxes_rate,
            'taxtype' => $this->tax_item()?->tax_type,
            'taxcode' => $this->tax_item()?->tax_code,
        ];
    }

    /**
     * Rails: `tax_line_items_with_adjusted_taxes` — pushes the tax rounding
     * remainder onto the first positively-taxed line.
     *
     * @return list<array<string, mixed>>
     */
    private function tax_line_items_with_adjusted_taxes(): array
    {
        $taxLineItems = $this->tax_line_items();

        $taxesAmountCentsSum = array_sum(array_map(
            fn (array $item) => (float) ($item['taxamount'] ?? 0),
            $taxLineItems,
        ));

        if ($taxesAmountCentsSum === $this->credit_note->taxes_amount_cents) {
            return $taxLineItems;
        }

        $adjustedFirstTax = false;

        return array_map(function (array $item) use (&$adjustedFirstTax, $taxesAmountCentsSum) {
            if ((float) $item['taxamount'] > 0 && ! $adjustedFirstTax) {
                $amount = (float) $this->credit_note_amount($this->credit_note->taxes_amount_cents);

                $item['taxamount'] = (string) ((float) $item['taxamount'] + $amount - $taxesAmountCentsSum);
                $adjustedFirstTax = true;
            }

            return $item;
        }, $taxLineItems);
    }

    /**
     * Rails: `taxes_amount(credit_note_item)` — amount_cents / subunit over
     * the credit note's flat taxes rate, rounded to 2 decimals.
     */
    private function netsuite_taxes_amount(CreditNoteItem $credit_note_item): float
    {
        $subunitToUnit = (float) \App\Support\Currency::subunitToUnit((string) $credit_note_item->amount_currency);

        return round((float) $credit_note_item->amount_cents / $subunitToUnit * (float) $credit_note_item->creditNote->taxes_rate, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(): array
    {
        $result = [
            'tranid' => $this->credit_note->number,
            'entity' => $this->integration_customer->external_customer_id,
            'taxregoverride' => true,
            'taxdetailsoverride' => true,
            'otherrefnum' => $this->credit_note->number,
            'custbody_ava_disable_tax_calculation' => true,
            'custbody_lago_id' => $this->credit_note->id,
            'tranId' => $this->credit_note->id,
        ];

        if ($this->tax_item()?->tax_nexus !== null) {
            $result['nexus'] = $this->tax_item()->tax_nexus;
        }

        return $result;
    }

    /**
     * Rails: `coupon_taxes` — the tax details line of the coupon adjustment.
     *
     * @return list<array<string, mixed>>
     */
    private function coupon_taxes(): array
    {
        $output = [];

        if ((int) $this->credit_note->coupons_adjustment_amount_cents > 0) {
            $output[] = [
                'taxbasis' => 1,
                'taxamount' => 0,
                'taxrate' => $this->credit_note->taxes_rate,
                'taxtype' => $this->tax_item()?->tax_type,
                'taxcode' => $this->tax_item()?->tax_code,
                'taxdetailsreference' => 'coupon_item',
            ];
        }

        return $output;
    }

    /**
     * Rails: `credit_note.invoice.credits.coupon_kind.map(&:item_name).join(",")`.
     */
    private function coupon_credits_description(): string
    {
        $credits = $this->credit_note->invoice->credits()
            ->whereNotNull('applied_coupon_id')
            ->get();

        return $credits
            ->map(fn ($credit) => (string) ($credit->appliedCoupon?->coupon?->name ?? ''))
            ->implode(',');
    }

    /**
     * Rails: `fee.item_name` (the parts the payload consumes; credit /
     * product fees are TODO(port)).
     */
    private function netsuite_fee_item_name($fee): ?string
    {
        $type = $fee->typeEnum();

        if ($type === \App\Enums\FeeType::Charge) {
            return $fee->charge?->billableMetric?->name;
        }

        if ($type === \App\Enums\FeeType::AddOn) {
            return $fee->addOn?->name;
        }

        if ($type === \App\Enums\FeeType::FixedCharge) {
            return $fee->fixedCharge?->addOn?->name;
        }

        if ($type === \App\Enums\FeeType::Subscription) {
            return $fee->subscription?->plan?->name;
        }

        return null; // TODO(port): credit / product fee item names.
    }
}
