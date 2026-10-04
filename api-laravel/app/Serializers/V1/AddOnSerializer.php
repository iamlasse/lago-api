<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\AddOn;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::AddOnSerializer
 * (app/serializers/v1/add_on_serializer.rb) — the taxes collection merges
 * into the payload only when the `taxes` include is requested.
 */
class AddOnSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var AddOn $addOn */
        $addOn = $this->model;

        $payload = [
            'lago_id' => $addOn->id,
            'name' => $addOn->name,
            'invoice_display_name' => $addOn->invoice_display_name,
            'code' => $addOn->code,
            'amount_cents' => $addOn->amount_cents,
            'amount_currency' => $addOn->amount_currency,
            'created_at' => $this->serializeDatetime($addOn->created_at),
            'description' => $addOn->description,
        ];

        if ($this->include('taxes')) {
            $payload = array_merge($payload, $this->taxes($addOn));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function taxes(AddOn $addOn): array
    {
        return (new CollectionSerializer(
            $addOn->taxes,
            TaxSerializer::class,
            ['collection_name' => 'taxes'],
        ))->serialize();
    }
}
