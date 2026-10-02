<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Support\Currency;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::FeeSerializer (app/serializers/v1/fee_serializer.rb).
 *
 * TODO(port): pricing_unit_details (pricing units not ported) and
 * presentation_breakdowns (M2 events pipeline); charge filter `filters` /
 * `filter_invoice_display_name` emit null until filters are ported.
 */
class FeeSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $model = $this->model;

        $subunitToUnit = Currency::subunitToUnit((string) $model->amount_currency);

        $payload = [
            'lago_id' => $model->id,
            'lago_charge_id' => $model->charge_id,
            'lago_charge_filter_id' => $model->charge_filter_id,
            'lago_fixed_charge_id' => $model->fixed_charge_id,
            'lago_invoice_id' => $model->invoice_id,
            'lago_true_up_fee_id' => \App\Models\Fee::query()
                ->where('true_up_parent_fee_id', $model->id)
                ->value('id'),
            'lago_true_up_parent_fee_id' => $model->true_up_parent_fee_id,
            'lago_original_fee_id' => $model->original_fee_id,
            'lago_subscription_id' => $model->subscription_id,
            'external_subscription_id' => $model->subscription?->external_id,
            'lago_customer_id' => $model->invoice?->customer_id ?? $model->subscription?->customer_id,
            'external_customer_id' => $model->invoice?->customer?->external_id
                ?? $model->subscription?->customer?->external_id,
            'item' => [
                'type' => $model->typeEnum()?->label(),
                'code' => $this->itemCode(),
                'name' => $this->itemName(),
                'description' => $this->itemDescription(),
                'invoice_display_name' => $model->invoice_display_name,
                'filters' => null,
                'filter_invoice_display_name' => null,
                'lago_item_id' => $this->itemId(),
                'item_type' => $this->itemType(),
                'grouped_by' => $model->grouped_by ?? (object) [],
            ],
            'pay_in_advance' => $this->payInAdvance(),
            'invoiceable' => $model->isCharge() ? $model->charge?->invoiceableCharge() : true,
            'amount_cents' => $model->amount_cents,
            'amount_currency' => $model->amount_currency,
            'precise_amount' => (float) bcdiv((string) $model->precise_amount_cents, (string) $subunitToUnit, 10),
            'precise_total_amount' => (float) bcdiv(
                bcadd((string) $model->precise_amount_cents, (string) $model->taxes_precise_amount_cents, 15),
                (string) $subunitToUnit,
                10,
            ),
            'taxes_amount_cents' => $model->taxes_amount_cents,
            'taxes_precise_amount' => (float) bcdiv((string) $model->taxes_precise_amount_cents, (string) $subunitToUnit, 10),
            'taxes_rate' => $model->taxes_rate,
            'total_aggregated_units' => $model->total_aggregated_units,
            'total_amount_cents' => (int) $model->amount_cents + (int) $model->taxes_amount_cents,
            'total_amount_currency' => $model->amount_currency,
            'units' => (float) $model->units,
            'description' => $model->description,
            'precise_unit_amount' => (float) bcdiv((string) $model->precise_unit_amount, '1', 10),
            'precise_coupons_amount_cents' => (float) $model->precise_coupons_amount_cents,
            'sub_total_excluding_taxes_amount_cents' => (int) round((float) $model->subTotalExcludingTaxesAmountCents()),
            'sub_total_excluding_taxes_precise_amount_cents' => (float) $model->subTotalExcludingTaxesPreciseAmountCents(),
            'events_count' => $model->events_count,
            'payment_status' => $model->paymentStatusEnum()?->label(),
            'created_at' => $this->serializeDatetime($model->created_at),
            'succeeded_at' => $this->serializeDatetime($model->succeeded_at),
            'failed_at' => $this->serializeDatetime($model->failed_at),
            'refunded_at' => $this->serializeDatetime($model->refunded_at),
            'amount_details' => $model->amount_details,
            'self_billed' => $model->invoice?->self_billed ?? false,
            'pricing_unit_details' => null,
            'presentation_breakdowns' => [],
        ];

        if (in_array($model->typeEnum()?->label(), ['charge', 'subscription', 'add_on', 'fixed_charge'], true)) {
            $payload = [...$payload, ...$this->dateBoundaries()];
        }

        if ($model->pay_in_advance && $model->isCharge()) {
            $payload['event_transaction_id'] = $model->pay_in_advance_event_transaction_id;
        }

        if ($this->include('applied_taxes')) {
            $payload = [...$payload, ...(new CollectionSerializer(
                $model->appliedTaxes,
                Fees\AppliedTaxSerializer::class,
                ['collection_name' => 'applied_taxes'],
            ))->serialize()];
        }

        return $payload;
    }

    private function payInAdvance(): bool
    {
        $model = $this->model;

        if ($model->isCharge() || $model->typeEnum()?->label() === 'fixed_charge') {
            return (bool) $model->pay_in_advance;
        }

        if ($model->isSubscription()) {
            return (bool) $model->subscription?->plan?->pay_in_advance;
        }

        return false;
    }

    // -- item accessors (port of Rails' Fee#item_*) ---------------------------

    private function itemCode(): ?string
    {
        return match ($this->model->typeEnum()?->label()) {
            'charge' => $this->model->charge?->billableMetric?->code,
            'add_on' => $this->model->addOn?->code,
            'subscription' => $this->model->subscription?->plan?->code,
            'fixed_charge' => $this->model->fixedCharge?->code ?? null,
            default => null,
        };
    }

    private function itemName(): ?string
    {
        return match ($this->model->typeEnum()?->label()) {
            'charge' => $this->model->charge?->billableMetric?->name,
            'add_on' => $this->model->addOn?->name,
            'subscription' => $this->model->subscription?->plan?->invoiceName(),
            default => null,
        };
    }

    private function itemDescription(): ?string
    {
        return match ($this->model->typeEnum()?->label()) {
            'charge' => $this->model->charge?->billableMetric?->description,
            'add_on' => $this->model->addOn?->description,
            default => null,
        };
    }

    private function itemId(): ?string
    {
        return match ($this->model->typeEnum()?->label()) {
            'charge' => $this->model->charge?->id,
            'add_on' => $this->model->addOn?->id,
            'subscription' => $this->model->subscription?->plan?->id,
            default => null,
        };
    }

    private function itemType(): ?string
    {
        return match ($this->model->typeEnum()?->label()) {
            'charge' => 'billable_metric',
            'add_on' => 'add_on',
            'subscription' => 'plan',
            default => null,
        };
    }

    /**
     * Port of Fee#date_boundaries — the fee `properties` boundary keys.
     *
     * @return array<string, mixed>
     */
    private function dateBoundaries(): array
    {
        $properties = $this->model->properties ?? [];

        $keys = [
            'from_datetime',
            'to_datetime',
            'charges_from_datetime',
            'charges_to_datetime',
            'fixed_charges_from_datetime',
            'fixed_charges_to_datetime',
        ];

        $out = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $properties)) {
                continue;
            }

            $out[$key] = $properties[$key] === null
                ? null
                : \Carbon\CarbonImmutable::parse($properties[$key], 'UTC')->toIso8601String();
        }

        return $out;
    }
}
