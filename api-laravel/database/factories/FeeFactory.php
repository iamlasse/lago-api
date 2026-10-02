<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeePaymentStatus;
use App\Enums\FeeType;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :fee factory.
 *
 * @extends Factory<Fee>
 */
class FeeFactory extends Factory
{
    protected $model = Fee::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'subscription_id' => SubscriptionFactory::new(),
            'billing_entity_id' => function (array $attributes): ?string {
                return Invoice::query()->find($attributes['invoice_id'])?->billing_entity_id;
            },
            'organization_id' => function (array $attributes): string {
                return Subscription::query()->find($attributes['subscription_id'])->organization_id;
            },
            'amount_cents' => $this->faker->randomNumber(4),
            'precise_amount_cents' => '0',
            'amount_currency' => 'EUR',
            'unit_amount_cents' => $this->faker->randomNumber(3),
            'precise_unit_amount' => '0',
            'taxes_amount_cents' => 0,
            'taxes_precise_amount_cents' => '0',
            'taxes_rate' => 0,
            'precise_coupons_amount_cents' => '0',
            'units' => '1',
            'fee_type' => FeeType::Subscription,
            'payment_status' => FeePaymentStatus::Pending,
            'properties' => [],
        ];
    }

    public function chargeFee(): static
    {
        return $this->state(fn () => ['fee_type' => FeeType::Charge]);
    }

    public function subscriptionFee(): static
    {
        return $this->state(fn () => ['fee_type' => FeeType::Subscription]);
    }
}
