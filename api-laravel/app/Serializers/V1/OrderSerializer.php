<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Order;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::OrderSerializer
 * (app/serializers/v1/order_serializer.rb).
 *
 * billing_snapshot is a heavy blob mirroring the quote version billing
 * items: rendered for API responses (the `billing_snapshot` include), never
 * in webhook payloads.
 */
class OrderSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var Order $order */
        $order = $this->model;

        $payload = [
            'lago_id' => $order->id,
            'number' => $order->number,
            'status' => $order->status?->label(),
            'order_type' => $order->orderType(),
            'execution_mode' => $order->execution_mode?->label(),
            'currency' => $order->currency(),
            'executed_at' => $this->serializeDatetime($order->executed_at),
            'execution_record' => $this->executionRecord($order),
            'lago_organization_id' => $order->organization_id,
            'lago_customer_id' => $order->customer_id,
            'lago_order_form_id' => $order->order_form_id,
            'created_at' => $this->serializeDatetime($order->created_at),
            'updated_at' => $this->serializeDatetime($order->updated_at),
        ];

        // Rails: payload[:billing_snapshot] = model.billing_snapshot if
        // include?(:billing_snapshot).
        if ($this->include('billing_snapshot')) {
            $payload['billing_snapshot'] = $order->billingSnapshot();
        }

        return $payload;
    }

    /**
     * Rails: `execution_record` — a record written before a key existed
     * carries no such key at all, so the shape is completed with the
     * EXECUTION_RECORD_DEFAULTS (Types::Orders::ExecutionRecord does the
     * same for GraphQL).
     *
     * @return array<string, mixed>
     */
    private function executionRecord(Order $order): array
    {
        /** @var array<string, mixed>|null $record */
        $record = $order->execution_record;

        return array_merge(Order::EXECUTION_RECORD_DEFAULTS, $record ?? []);
    }
}
