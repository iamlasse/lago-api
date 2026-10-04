<?php

declare(strict_types=1);

namespace App\Serializers\V1\CreditNotes;

use Illuminate\Support\Arr;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;

/**
 * Port of Rails' V1::CreditNotes::EstimateSerializer
 * (app/serializers/v1/credit_notes/estimate_serializer.rb) — renders the
 * unpersisted credit note EstimateService builds, under the
 * `estimated_credit_note` root.
 */
class EstimateSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $model = $this->model;

        $payload = [
            'lago_invoice_id' => $model->invoice_id,
            'invoice_number' => $model->invoice->number,
            'currency' => $model->currency(),
            'taxes_amount_cents' => $model->taxes_amount_cents,
            'precise_taxes_amount_cents' => $model->preciseTaxesAmount(),
            'sub_total_excluding_taxes_amount_cents' => $model->subTotalExcludingTaxesAmountCents(),
            'max_creditable_amount_cents' => $model->credit_amount_cents,
            'max_refundable_amount_cents' => $model->refund_amount_cents,
            'coupons_adjustment_amount_cents' => $model->coupons_adjustment_amount_cents,
            'precise_coupons_adjustment_amount_cents' => $model->preciseCouponsAdjustment(),
            'taxes_rate' => $model->taxes_rate,
        ];

        return [...$payload, ...$this->items(), ...$this->appliedTaxes()];
    }

    /** @return array<string, mixed> */
    private function items(): array
    {
        return [
            'items' => $this->model->items
                ->map(fn ($item): array => [
                    'lago_fee_id' => $item->fee_id,
                    'amount_cents' => $item->amount_cents,
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function appliedTaxes(): array
    {
        $collection = (new CollectionSerializer(
            $this->model->appliedTaxes,
            AppliedTaxSerializer::class,
        ))->serialize();

        return [
            'applied_taxes' => array_map(
                fn (array $tax): array => Arr::except($tax, ['lago_id', 'lago_credit_note_id', 'created_at']),
                $collection['data'] ?? [],
            ),
        ];
    }
}
