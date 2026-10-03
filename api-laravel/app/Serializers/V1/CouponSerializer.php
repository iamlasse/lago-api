<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Coupon;
use App\Support\MoneyMath;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::CouponSerializer
 * (app/serializers/v1/coupon_serializer.rb).
 */
class CouponSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        /** @var Coupon $coupon */
        $coupon = $this->model;

        return [
            'lago_id' => $coupon->id,
            'name' => $coupon->name,
            'code' => $coupon->code,
            'description' => $coupon->description,
            'coupon_type' => $coupon->typeEnum()?->label(),
            'amount_cents' => $coupon->amount_cents,
            'amount_currency' => $coupon->amount_currency,
            'percentage_rate' => $coupon->percentage_rate !== null
                ? MoneyMath::toF((string) $coupon->percentage_rate)
                : null,
            'frequency' => $coupon->frequencyEnum()?->label(),
            'frequency_duration' => $coupon->frequency_duration,
            'reusable' => $coupon->reusable,
            'limited_plans' => $coupon->limited_plans,
            'limited_billable_metrics' => $coupon->limited_billable_metrics,
            // Rails: model.plans.parents.pluck(:code) — parent plans only,
            // the overrides (children) are excluded.
            'plan_codes' => $coupon->plans()->parents()->pluck('plans.code')->all(),
            'billable_metric_codes' => $coupon->billableMetrics()->pluck('billable_metrics.code')->all(),
            'created_at' => $this->serializeDatetime($coupon->created_at),
            'expiration' => $coupon->expirationEnum()?->label(),
            'expiration_at' => $this->serializeDatetime($coupon->expiration_at),
            'terminated_at' => $this->serializeDatetime($coupon->terminated_at),
        ];
    }
}
