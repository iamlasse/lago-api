<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\PaymentMethod;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::PaymentMethodSerializer
 * (app/serializers/v1/payment_method_serializer.rb).
 */
class PaymentMethodSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var PaymentMethod $method */
        $method = $this->model;

        return [
            'lago_id' => $method->id,
            'is_default' => $method->is_default,
            'payment_provider_code' => $method->paymentProvider?->code,
            'payment_provider_name' => $method->paymentProvider?->name,
            'payment_provider_type' => $method->paymentProviderType(),
            'provider_method_id' => $method->provider_method_id,
            'created_at' => $this->serializeDatetime($method->created_at),
        ];
    }
}
