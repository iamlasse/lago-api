<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Support\MoneyMath;
use App\Models\AppliedCoupon;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\Base\CollectionSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::AppliedCouponSerializer
 * (app/serializers/v1/applied_coupon_serializer.rb).
 */
class AppliedCouponSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var AppliedCoupon $appliedCoupon */
        $appliedCoupon = $this->model;
        $coupon = $appliedCoupon->coupon;

        $payload = [
            'lago_id' => $appliedCoupon->id,
            'lago_coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'coupon_name' => $coupon->name,
            'coupon_description' => $coupon->description,
            'coupon_status' => $coupon->statusEnum()?->label(),
            'coupon_deleted_at' => $this->serializeDatetime($coupon->deleted_at),
            'lago_customer_id' => $appliedCoupon->customer->id,
            'external_customer_id' => $appliedCoupon->customer->external_id,
            'status' => $appliedCoupon->statusEnum()?->label(),
            'amount_cents' => $appliedCoupon->amount_cents,
            'amount_cents_remaining' => $this->amountCentsRemaining(),
            'amount_currency' => $appliedCoupon->amount_currency,
            'percentage_rate' => $appliedCoupon->percentage_rate !== null
                ? MoneyMath::toF((string) $appliedCoupon->percentage_rate)
                : null,
            'frequency' => $appliedCoupon->frequencyEnum()?->label(),
            'frequency_duration' => $appliedCoupon->frequency_duration,
            'frequency_duration_remaining' => $appliedCoupon->frequency_duration_remaining,
            'expiration_at' => $this->serializeDatetime($coupon->expiration_at),
            'created_at' => $this->serializeDatetime($appliedCoupon->created_at),
            'terminated_at' => $this->serializeDatetime($appliedCoupon->terminated_at),
        ];

        if ($this->include('credits')) {
            $payload = [...$payload, ...$this->credits()];
        }

        return $payload;
    }

    private function amountCentsRemaining(): ?int
    {
        /** @var AppliedCoupon $appliedCoupon */
        $appliedCoupon = $this->model;

        if ($appliedCoupon->recurring() || $appliedCoupon->forever()) {
            return null;
        }

        if ($appliedCoupon->coupon->percentage()) {
            return null;
        }

        return (int) $appliedCoupon->amount_cents - $appliedCoupon->activeCreditsAmountCents();
    }

    /** @return array<string, mixed> */
    private function credits(): array
    {
        /** @var AppliedCoupon $appliedCoupon */
        $appliedCoupon = $this->model;

        return (new CollectionSerializer(
            $appliedCoupon->credits,
            CreditSerializer::class,
            ['collection_name' => 'credits'],
        ))->serialize();
    }
}
