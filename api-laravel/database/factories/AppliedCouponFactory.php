<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\AppliedCoupon;
use App\Enums\CouponFrequency;
use App\Enums\AppliedCouponStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :applied_coupon factory.
 *
 * @extends Factory<AppliedCoupon>
 */
class AppliedCouponFactory extends Factory
{
    protected $model = AppliedCoupon::class;

    public function definition(): array
    {
        return [
            'coupon_id' => CouponFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'organization_id' => function (array $attributes): string {
                return Customer::query()->find($attributes['customer_id'])->organization_id;
            },
            'status' => AppliedCouponStatus::Active,
            'frequency' => function (array $attributes): int {
                return Coupon::query()->find($attributes['coupon_id'])?->frequency?->value ?? CouponFrequency::Once->value;
            },
            'frequency_duration' => function (array $attributes): int {
                return (int) (Coupon::query()->find($attributes['coupon_id'])->frequency_duration ?? 1);
            },
            'frequency_duration_remaining' => function (array $attributes): int {
                $coupon = Coupon::query()->find($attributes['coupon_id']);

                return (int) ($coupon?->frequency_duration ?? 1);
            },
            'amount_cents' => 1000,
            'amount_currency' => 'EUR',
        ];
    }

    public function percentage(string $rate = '20'): static
    {
        return $this->state(fn () => ['percentage_rate' => $rate]);
    }

    public function recurring(int $duration = 3): static
    {
        return $this->state(fn () => [
            'frequency' => CouponFrequency::Recurring,
            'frequency_duration' => $duration,
            'frequency_duration_remaining' => $duration,
        ]);
    }

    public function forever(): static
    {
        return $this->state(fn () => ['frequency' => CouponFrequency::Forever]);
    }

    public function terminated(): static
    {
        return $this->state(fn () => [
            'status' => AppliedCouponStatus::Terminated,
            'terminated_at' => now('UTC'),
        ]);
    }
}
