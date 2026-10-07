<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Invoices\Payloads;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Invoice;
use App\Models\IntegrationCustomer;
use App\Services\Integrations\Aggregator\BasePayload\Failure;
use App\Services\Integrations\Aggregator\BasePayload as AggregatorBasePayload;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::Payloads::BasePayload
 * (…/aggregator/invoices/payloads/base_payload.rb) — the shared ACCREC
 * invoice shape plus the item-code mapping layer.
 *
 * The item dispatch resolves through the IntegrationMappings /
 * IntegrationCollectionMappings rows (see the aggregator BasePayload); a
 * fee whose mapping is missing raises the "invalid_mapping" failure exactly
 * like Rails without mappings configured.
 */
abstract class BasePayload extends AggregatorBasePayload
{
    protected readonly IntegrationCustomer $integration_customer;

    protected int $remaining_taxes_amount_cents = 0;

    public function __construct(
        IntegrationCustomer $integration_customer,
        public readonly Invoice $invoice,
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
                'issuing_date' => \Illuminate\Support\Facades\Date::parse($this->invoice->issuing_date)->toAtomString(),
                'payment_due_date' => \Illuminate\Support\Facades\Date::parse($this->invoice->payment_due_date)->toAtomString(),
                'number' => $this->invoice->number,
                'currency' => $this->invoice->currency,
                'type' => 'ACCREC',
                'fees' => $this->tax_adjusted_fee_items(),
            ],
        ];
    }

    /** Rails: `integration_invoice` — the recorded IntegrationResource, if any. */
    public function integration_invoice(): ?object
    {
        return \App\Models\IntegrationResource::query()
            ->where('integration_id', $this->integration_customer->integration_id)
            ->where('syncable_id', $this->invoice->id)
            ->where('syncable_type', 'Invoice')
            ->where('resource_type', \App\Models\IntegrationResource::RESOURCE_TYPE_INVOICE)
            ->first();
    }

    /**
     * Rails: `fees` — positive fees first, else all fees (both ordered by
     * created_at).
     *
     * @return \Illuminate\Support\Collection<int, Fee>
     */
    protected function fees(): \Illuminate\Support\Collection
    {
        return $this->invoice->fees()->oldest()->get()
            ->when(
                $this->invoice->fees()->where('amount_cents', '>', 0)->exists(),
                fn ($all) => $all->filter(fn (Fee $fee) => $fee->amount_cents > 0)->values(),
            );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fee_items(): array
    {
        return $this->fees()->map(fn (Fee $fee) => $this->item($fee))->all();
    }

    /**
     * Rails: `tax_adjusted_fee_items` — pushes the tax rounding remainder
     * onto a fee (no-coupon invoices only).
     *
     * @return list<array<string, mixed>>
     */
    protected function tax_adjusted_fee_items(): array
    {
        $feeItems = $this->fee_items();

        $feeTaxesTotal = (int) round(array_sum(array_map(
            fn (array $fee) => (int) ($fee['taxes_amount_cents'] ?? 0),
            $feeItems,
        )));

        $this->remaining_taxes_amount_cents = $this->invoice->taxes_amount_cents - $feeTaxesTotal;

        return array_map(function (array $fee) {
            if (
                abs($this->remaining_taxes_amount_cents) > 0
                && $this->invoice->coupons_amount_cents === 0
                && (int) $fee['taxes_amount_cents'] > abs($this->remaining_taxes_amount_cents)
            ) {
                $fee['taxes_amount_cents'] = (int) $fee['taxes_amount_cents'] + $this->remaining_taxes_amount_cents;
                $this->remaining_taxes_amount_cents = 0;
            }

            return $fee;
        }, $feeItems);
    }

    /**
     * @return array<string, mixed>
     */
    protected function item(Fee $fee): array
    {
        $mappedItem = $this->mapped_invoice_item($fee);

        if ($mappedItem === null) {
            throw new Failure('invalid_mapping');
        }

        return [
            'external_id' => $mappedItem->external_id,
            'description' => $this->isSubscriptionFee($fee)
                ? 'Subscription'
                : ($fee->chargeFilter?->displayName() ?: $fee->invoice_display_name),
            'units' => $fee->units,
            'precise_unit_amount' => $fee->precise_unit_amount,
            'account_code' => $mappedItem->external_account_code,
            'taxes_amount_cents' => $fee->taxes_amount_cents,
        ];
    }

    /**
     * Rails: `discounts` — the coupon / prepaid credit / progressive
     * billing / credit note deduction lines.
     *
     * @return list<array<string, mixed>>
     */
    protected function discounts(): array
    {
        $output = [];

        if ($this->invoice->coupons_amount_cents > 0) {
            $taxDiffAmountCents = $this->invoice->taxes_amount_cents - array_sum(array_map(
                fn (array $fee) => (int) ($fee['taxes_amount_cents'] ?? 0),
                $this->fee_items(),
            ));

            $output[] = [
                'external_id' => $this->coupon_item()?->external_id,
                'description' => 'Coupons',
                'units' => 1,
                'precise_unit_amount' => -$this->amount($this->invoice->coupons_amount_cents, resource: $this->invoice),
                'taxes_amount_cents' => -abs((int) $taxDiffAmountCents),
                'account_code' => $this->coupon_item()?->external_account_code,
            ];
        }

        if ($this->credit_item() !== null && $this->invoice->prepaid_credit_amount_cents > 0) {
            $output[] = [
                'external_id' => $this->credit_item()?->external_id,
                'description' => 'Prepaid credit',
                'units' => 1,
                'precise_unit_amount' => -$this->amount($this->invoice->prepaid_credit_amount_cents, resource: $this->invoice),
                'taxes_amount_cents' => 0,
                'account_code' => $this->credit_item()?->external_account_code,
            ];
        }

        if ($this->credit_item() !== null && $this->invoice->progressive_billing_credit_amount_cents > 0) {
            $output[] = [
                'external_id' => $this->credit_item()?->external_id,
                'description' => 'Usage already billed',
                'units' => 1,
                'precise_unit_amount' => -$this->amount($this->invoice->progressive_billing_credit_amount_cents, resource: $this->invoice),
                'taxes_amount_cents' => 0,
                'account_code' => $this->credit_item()?->external_account_code,
            ];
        }

        if ($this->credit_note_item() !== null && $this->invoice->credit_notes_amount_cents > 0) {
            $output[] = [
                'external_id' => $this->credit_note_item()?->external_id,
                'description' => 'Credit note',
                'units' => 1,
                'precise_unit_amount' => -$this->amount($this->invoice->credit_notes_amount_cents, resource: $this->invoice),
                'taxes_amount_cents' => 0,
                'account_code' => $this->credit_note_item()?->external_account_code,
            ];
        }

        return $output;
    }

    /** Rails: `invoice_url` — the front-app deep link of the invoice. */
    protected function invoice_url(): string
    {
        return (string) $this->invoice->webUrl();
    }

    /** Rails' item() mapping dispatch. */
    protected function mapped_invoice_item(Fee $fee): ?object
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
}
