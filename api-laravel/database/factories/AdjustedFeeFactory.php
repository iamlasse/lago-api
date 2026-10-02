<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\AdjustedFee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :adjusted_fee factory.
 *
 * @extends Factory<AdjustedFee>
 */
class AdjustedFeeFactory extends Factory
{
    protected $model = AdjustedFee::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new()->draft(),
            'subscription_id' => SubscriptionFactory::new(),
            'organization_id' => function (array $attributes): string {
                return Invoice::query()->find($attributes['invoice_id'])->organization_id;
            },
            'fee_type' => \App\Enums\FeeType::Subscription,
            'adjusted_units' => false,
            'adjusted_amount' => false,
            'units' => '1',
            'unit_amount_cents' => 0,
            'unit_precise_amount_cents' => '0',
            'properties' => [],
            'grouped_by' => [],
        ];
    }
}
