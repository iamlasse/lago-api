<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Support\MoneyMath;
use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::CreditNoteItemSerializer
 * (app/serializers/v1/credit_note_item_serializer.rb).
 */
class CreditNoteItemSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $model = $this->model;

        return [
            'lago_id' => $model->id,
            'amount_cents' => $model->amount_cents,
            // Rails renders the BigDecimal with to_s("F") at JSON-encode time.
            'precise_amount_cents' => MoneyMath::toF((string) $model->precise_amount_cents),
            'amount_currency' => $model->amount_currency,
            'fee' => (new FeeSerializer($model->fee))->serialize(),
        ];
    }
}
