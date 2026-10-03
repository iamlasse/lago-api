<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;
use App\Serializers\V1\CreditNotes\AppliedTaxSerializer;

/**
 * Port of Rails' V1::CreditNoteSerializer
 * (app/serializers/v1/credit_note_serializer.rb).
 *
 * TODO(port) includes, waiting on features not yet in the Laravel port:
 * error_details (ErrorDetail model), metadata (Metadata::ItemMetadata);
 * file_url / xml_url (ActiveStorage).
 */
class CreditNoteSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $model = $this->model;

        $payload = [
            'lago_id' => $model->id,
            'billing_entity_code' => $model->invoice->billingEntity?->code,
            'sequential_id' => $model->sequential_id,
            'number' => $model->number,
            'lago_invoice_id' => $model->invoice_id,
            'invoice_number' => $model->invoice->number,
            'purchase_order_number' => $model->purchaseOrderNumber(),
            'issuing_date' => $this->serializeDateIso($model->issuing_date),
            'credit_status' => $model->creditStatusEnum()?->label(),
            'refund_status' => $model->refundStatusEnum()?->label(),
            'reason' => $model->reasonEnum()?->label(),
            'description' => $model->description,
            'currency' => $model->currency(),
            'total_amount_cents' => $model->total_amount_cents,
            'precise_total_amount_cents' => (string) $model->preciseTotal(),
            'taxes_amount_cents' => $model->taxes_amount_cents,
            'precise_taxes_amount_cents' => (string) $model->precise_taxes_amount_cents,
            'sub_total_excluding_taxes_amount_cents' => $model->subTotalExcludingTaxesAmountCents(),
            'balance_amount_cents' => $model->balance_amount_cents,
            'credit_amount_cents' => $model->credit_amount_cents,
            'refund_amount_cents' => $model->refund_amount_cents,
            'offset_amount_cents' => $model->offset_amount_cents,
            'coupons_adjustment_amount_cents' => $model->coupons_adjustment_amount_cents,
            'taxes_rate' => $model->taxes_rate,
            'created_at' => $this->serializeDatetime($model->created_at),
            'updated_at' => $this->serializeDatetime($model->updated_at),
            'file_url' => $model->fileUrl(),
            'xml_url' => $model->xmlUrl(),
            'self_billed' => $model->invoice->self_billed,
        ];

        if ($this->include('customer')) {
            $payload = [...$payload, ...$this->customer()];
        }

        if ($this->include('items')) {
            $payload = [...$payload, ...$this->items()];
        }

        if ($this->include('applied_taxes')) {
            $payload = [...$payload, ...$this->appliedTaxes()];
        }

        if ($this->include('error_details')) {
            // TODO(port): ErrorDetail model.
            $payload['error_details'] = [];
        }

        if ($model->metadata !== null) {
            // TODO(port): V1::MetadataSerializer (Metadata::ItemMetadata).
            $payload['metadata'] = null;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function customer(): array
    {
        return [
            'customer' => (new CustomerSerializer(
                $this->model->customer,
                $this->includedRelations('customer'),
            ))->serialize(),
        ];
    }

    /** @return array<string, mixed> */
    private function items(): array
    {
        $items = $this->model->items->sortBy(fn ($item) => $item->created_at)->values();

        return (new CollectionSerializer(
            $items,
            CreditNoteItemSerializer::class,
            ['collection_name' => 'items'],
        ))->serialize();
    }

    /** @return array<string, mixed> */
    private function appliedTaxes(): array
    {
        return (new CollectionSerializer(
            $this->model->appliedTaxes,
            AppliedTaxSerializer::class,
            ['collection_name' => 'applied_taxes'],
        ))->serialize();
    }

    /** Rails `iso8601` on a date. */
    private function serializeDateIso(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if (is_string($date)) {
            $date = \Carbon\CarbonImmutable::parse($date, 'UTC');
        }

        return $date->format('Y-m-d');
    }
}
