<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\CreditNotes\Payloads;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\BasePayload\Failure;
use App\Services\Integrations\Aggregator\BasePayload as AggregatorBasePayload;

/**
 * Port of Rails' Integrations::Aggregator::CreditNotes::Payloads::BasePayload
 * (…/aggregator/credit_notes/payloads/base_payload.rb) — the shared ACCRECCREDIT
 * credit note shape plus the item-code mapping layer (the end-to-end mapping
 * resolution off the aggregator BasePayload: lookup_mapping /
 * lookup_collection_mapping / fallback_item).
 *
 * A fee whose mapping is missing raises the "invalid_mapping" failure exactly
 * like Rails without mappings configured.
 */
abstract class BasePayload extends AggregatorBasePayload
{
    protected readonly IntegrationCustomer $integration_customer;

    public function __construct(
        IntegrationCustomer $integration_customer,
        public readonly CreditNote $credit_note,
    ) {
        parent::__construct($integration_customer->integration, $integration_customer->customer->billingEntity);

        $this->integration_customer = $integration_customer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function body(): array
    {
        return [
            [
                'external_contact_id' => $this->integration_customer->external_customer_id,
                'status' => 'AUTHORISED',
                'issuing_date' => \Illuminate\Support\Facades\Date::parse($this->credit_note->issuing_date)->toAtomString(),
                'number' => $this->credit_note->number,
                'currency' => $this->credit_note->currency(),
                'type' => 'ACCRECCREDIT',
                'fees' => array_merge($this->credit_note_items_with_adjusted_taxes($this->credit_note_items()), $this->coupons()),
            ],
        ];
    }

    /** Rails: `integration_credit_note` — the recorded IntegrationResource, if any. */
    public function integration_credit_note(): ?object
    {
        return \App\Models\IntegrationResource::query()
            ->where('integration_id', $this->integration_customer->integration_id)
            ->where('syncable_id', $this->credit_note->id)
            ->where('syncable_type', 'CreditNote')
            ->where('resource_type', \App\Models\IntegrationResource::RESOURCE_TYPE_CREDIT_NOTE)
            ->first();
    }

    /**
     * Rails: `credit_note_items` — the item payloads, in the default items
     * order (the Netsuite payload re-orders by created_at).
     *
     * @return list<array<string, mixed>>
     */
    protected function credit_note_items(): array
    {
        return $this->credit_note->items->map(fn (CreditNoteItem $item) => $this->item($item))->all();
    }

    /**
     * Rails: `credit_note_items_with_adjusted_taxes` — pushes the tax rounding
     * remainder onto the first positively-taxed item.
     *
     * @param  list<array<string, mixed>>  $credit_note_items
     * @return list<array<string, mixed>>
     */
    protected function credit_note_items_with_adjusted_taxes(array $credit_note_items): array
    {
        $taxesAmountCentsSum = array_sum(array_map(
            fn (array $item) => (float) ($item['taxes_amount_cents'] ?? 0),
            $credit_note_items,
        ));

        if ($taxesAmountCentsSum === $this->credit_note->taxes_amount_cents) {
            return $credit_note_items;
        }

        $adjustedFirstTax = false;

        return array_map(function (array $item) use (&$adjustedFirstTax, $taxesAmountCentsSum) {
            if ((float) $item['taxes_amount_cents'] > 0 && ! $adjustedFirstTax) {
                $item['taxes_amount_cents'] = (string) (
                    (float) $item['taxes_amount_cents']
                    + $this->credit_note->taxes_amount_cents
                    - $taxesAmountCentsSum
                );
                $adjustedFirstTax = true;
            }

            return $item;
        }, $credit_note_items);
    }

    /**
     * @return array<string, mixed>
     */
    protected function item(CreditNoteItem $credit_note_item): array
    {
        $fee = $credit_note_item->fee;

        $mappedItem = $this->mapped_credit_note_item($fee);

        if ($mappedItem === null) {
            throw new Failure('invalid_mapping');
        }

        $preciseUnitAmount = (int) $credit_note_item->amount_cents;

        return [
            'external_id' => $mappedItem->external_id,
            'description' => $this->isSubscriptionFee($fee) ? 'Subscription' : $this->fee_invoice_name($fee),
            'units' => $preciseUnitAmount > 0 ? 1 : 0,
            'precise_unit_amount' => $this->credit_note_amount($preciseUnitAmount),
            'account_code' => $mappedItem->external_account_code,
            'taxes_amount_cents' => $this->credit_note_amount($this->taxes_amount_cents($credit_note_item)),
        ];
    }

    /**
     * Rails: `taxes_amount_cents(credit_note_item)` — the item amount over the
     * credit note's flat taxes rate.
     */
    protected function taxes_amount_cents(CreditNoteItem $credit_note_item): float
    {
        return (float) $credit_note_item->amount_cents * (float) $credit_note_item->creditNote->taxes_rate;
    }

    /**
     * Rails: `coupons` — the coupon adjustment line.
     *
     * @return list<array<string, mixed>>
     */
    protected function coupons(): array
    {
        $output = [];

        $couponsAmountCents = (int) $this->credit_note->coupons_adjustment_amount_cents;

        if ($couponsAmountCents > 0) {
            $output[] = [
                'external_id' => $this->coupon_item()?->external_id,
                'description' => 'Coupons',
                'units' => 1,
                'precise_unit_amount' => (string) -$this->credit_note_amount($couponsAmountCents),
                'taxes_amount_cents' => 0,
                'account_code' => $this->coupon_item()?->external_account_code,
            ];
        }

        return $output;
    }

    /**
     * Rails passes the credit note as the `amount` resource
     * (`resource.total_amount.currency`) — the shared helper reads the
     * `amount_currency` attribute, which the frozen credit-notes schema
     * carries as `total_amount_currency`.
     */
    protected function credit_note_amount(int|float $amountCents): string
    {
        return $this->amount($amountCents, resource: (object) [
            'amount_currency' => $this->credit_note->currency(),
        ]);
    }

    /** Rails' item() mapping dispatch (fee.charge?/add_on?/…). */
    protected function mapped_credit_note_item(Fee $fee): ?object
    {
        $type = $fee->typeEnum();

        if ($type === FeeType::Charge) {
            return $this->billable_metric_item($fee);
        }

        if ($type === FeeType::AddOn) {
            return $this->add_on_item($fee);
        }

        if ($type === FeeType::FixedCharge) {
            return $this->fixed_charge_item($fee);
        }

        if ($type === FeeType::Credit) {
            return $this->credit_item();
        }

        if ($type === FeeType::Commitment) {
            return $this->commitment_item();
        }

        if ($type === FeeType::Subscription) {
            return $this->subscription_item();
        }

        return null;
    }

    protected function isSubscriptionFee(Fee $fee): bool
    {
        return $fee->typeEnum() === FeeType::Subscription;
    }

    /**
     * Rails: `fee.invoice_name` — the payload line description fallback (the
     * parts the payload consumes; credit / product fees are TODO(port)).
     */
    protected function fee_invoice_name(Fee $fee): ?string
    {
        if (($fee->invoice_display_name ?? null) !== null && $fee->invoice_display_name !== '') {
            return (string) $fee->invoice_display_name;
        }

        $type = $fee->typeEnum();

        if ($type === FeeType::Charge) {
            return ($fee->charge?->invoice_display_name ?? null) !== null && $fee->charge->invoice_display_name !== ''
                ? (string) $fee->charge->invoice_display_name
                : $fee->charge?->billableMetric?->name;
        }

        if ($type === FeeType::AddOn) {
            return $fee->addOn?->invoiceName();
        }

        if ($type === FeeType::FixedCharge) {
            return ($fee->fixedCharge?->invoice_display_name ?? null) !== null && $fee->fixedCharge->invoice_display_name !== ''
                ? (string) $fee->fixedCharge->invoice_display_name
                : $fee->fixedCharge?->addOn?->invoiceName();
        }

        if ($type === FeeType::Subscription) {
            return $fee->subscription?->invoiceName();
        }

        return null; // TODO(port): credit / product fee invoice names.
    }
}
