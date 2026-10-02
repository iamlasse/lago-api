<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Coupon;
use App\Enums\CouponType;
use App\Enums\CouponStatus;
use App\Enums\CouponFrequency;
use App\Enums\CouponExpiration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :coupon factory (spec/factories/coupons.rb).
 *
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'name' => 'Coupon '.$this->faker->uuid(),
            'code' => 'COUPON-'.$this->faker->uuid(),
            'status' => CouponStatus::Active,
            'coupon_type' => CouponType::FixedAmount,
            'amount_cents' => 1000,
            'amount_currency' => 'EUR',
            'frequency' => CouponFrequency::Once,
            'frequency_duration' => 1,
            'expiration' => CouponExpiration::NoExpiration,
            'reusable' => false,
            'limited_plans' => false,
            'limited_billable_metrics' => false,
        ];
    }

    public function percentage(string $rate = '20'): static
    {
        return $this->state(fn () => [
            'coupon_type' => CouponType::Percentage,
            'percentage_rate' => $rate,
        ]);
    }

    public function recurring(int $duration = 3): static
    {
        return $this->state(fn () => [
            'frequency' => CouponFrequency::Recurring,
            'frequency_duration' => $duration,
        ]);
    }

    public function forever(): static
    {
        return $this->state(fn () => ['frequency' => CouponFrequency::Forever]);
    }
}
