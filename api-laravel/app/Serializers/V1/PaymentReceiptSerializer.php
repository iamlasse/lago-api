<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\PaymentReceipt;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::PaymentReceiptSerializer
 * (app/serializers/v1/payment_receipt_serializer.rb).
 *
 * `number` re-reads the row (Rails: model.reload.number) — the frozen
 * schema's set_payment_receipt_number() trigger assigns the number during
 * the INSERT, after the in-memory attributes were built.
 */
class PaymentReceiptSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var PaymentReceipt $receipt */
        $receipt = $this->model;
        $number = PaymentReceipt::query()->find($receipt->id)?->number ?? $receipt->number;

        return [
            'lago_id' => $receipt->id,
            'number' => $number,
            'file_url' => $receipt->fileUrl(),
            'xml_url' => $receipt->xmlUrl(),
            'payment' => (new PaymentSerializer($receipt->payment))->serialize(),
            'created_at' => $this->serializeDatetime($receipt->created_at),
        ];
    }
}
