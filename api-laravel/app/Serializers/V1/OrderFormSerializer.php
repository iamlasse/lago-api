<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\OrderForm;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::OrderFormSerializer
 * (app/serializers/v1/order_form_serializer.rb).
 *
 * signed_document_url is always null in the port: ActiveStorage blobs are
 * not ported yet (Rails: rails_blob_url over the attached document).
 */
class OrderFormSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var OrderForm $orderForm */
        $orderForm = $this->model;

        return [
            'lago_id' => $orderForm->id,
            'number' => $orderForm->number,
            'status' => $orderForm->status,
            'void_reason' => $orderForm->void_reason,
            'expires_at' => $this->serializeDatetime($orderForm->expires_at),
            'signed_at' => $this->serializeDatetime($orderForm->signed_at),
            'voided_at' => $this->serializeDatetime($orderForm->voided_at),
            'signed_document_url' => null, // TODO(port): signed_document attachment URL.
            'lago_organization_id' => $orderForm->organization_id,
            'lago_customer_id' => $orderForm->customer_id,
            'lago_quote_id' => $orderForm->quoteVersion->quote_id,
            'lago_quote_version_id' => $orderForm->quote_version_id,
            'created_at' => $this->serializeDatetime($orderForm->created_at),
            'updated_at' => $this->serializeDatetime($orderForm->updated_at),
        ];
    }
}
