<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\PaymentRequest;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::PaymentRequestSerializer
 * (app/serializers/v1/payment_request_serializer.rb) — customer / invoices
 * are opt-in includes (index & create pass both).
 */
class PaymentRequestSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var PaymentRequest $request */
        $request = $this->model;

        $payload = [
            'lago_id' => $request->id,
            'amount_cents' => $request->amount_cents,
            'amount_currency' => $request->amount_currency,
            'email' => $request->email,
            'payment_status' => $request->paymentStatus(),
            'created_at' => $this->serializeDatetime($request->created_at),
        ];

        if ($this->include('customer')) {
            $payload['customer'] = [
                'customer' => (new CustomerSerializer($request->customer))->serialize(),
            ];
        }

        if ($this->include('invoices')) {
            $payload += (new CollectionSerializer(
                $request->invoices,
                InvoiceSerializer::class,
                ['collection_name' => 'invoices'],
            ))->serialize();
        }

        return $payload;
    }
}
