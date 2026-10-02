<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::CreditSerializer (app/serializers/v1/credit_serializer.rb).
 */
class CreditSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        $model = $this->model;

        return [
            'lago_id' => $model->id,
            'amount_cents' => $model->amount_cents,
            'amount_currency' => $model->amount_currency,
            'before_taxes' => $model->before_taxes,
            'item' => [
                'lago_item_id' => $this->itemId(),
                'type' => $this->itemType(),
                'code' => $this->itemCode(),
                'name' => $this->itemName(),
                'description' => $this->itemDescription(),
            ],
            'invoice' => [
                'lago_id' => $model->invoice_id,
                'payment_status' => $model->invoice?->paymentStatusEnum()?->label(),
            ],
        ];
    }

    // -- Rails Credit#item_* accessors ----------------------------------------

    private function itemId(): ?string
    {
        if ($this->model->applied_coupon_id !== null) {
            return $this->model->appliedCoupon?->coupon_id;
        }

        if ($this->model->progressive_billing_invoice_id !== null) {
            return $this->model->progressive_billing_invoice_id;
        }

        return $this->model->credit_note_id;
    }

    private function itemType(): string
    {
        if ($this->model->applied_coupon_id !== null) {
            return 'coupon';
        }

        if ($this->model->progressive_billing_invoice_id !== null) {
            return 'progressive_billing_invoice';
        }

        return 'credit_note';
    }

    private function itemCode(): ?string
    {
        return $this->model->appliedCoupon?->coupon?->code;
    }

    private function itemName(): ?string
    {
        return $this->model->appliedCoupon?->coupon?->name;
    }

    private function itemDescription(): ?string
    {
        return $this->model->appliedCoupon?->coupon?->description;
    }
}
